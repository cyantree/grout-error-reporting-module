<?php
namespace Grout\Cyantree\ErrorReportingModule;

use Cyantree\Grout\App\Module;
use Cyantree\Grout\DateTime\DateTime;
use Cyantree\Grout\ErrorWrapper\ErrorWrapper;
use Cyantree\Grout\Mail\Mail;
use Cyantree\Grout\Event\Event;
use Exception;
use Grout\Cyantree\ErrorReportingModule\Pages\ErrorReportingPage;
use Grout\Cyantree\ErrorReportingModule\Types\ErrorReportingConfig;
use Grout\Cyantree\ErrorReportingModule\Types\ScriptError;

class ErrorReportingModule extends Module
{
    const MODE_LOG = 'log';
    const MODE_SHOW = 'show';
    const MODE_AUTO = 'auto';

    private static $started;

    public $suppressErrors = false;

    private $reportedErrors = false;
    private $errorFileTimestamp = 0;
    private $reportedErrorSignatures = array();

    private $previousErrorReporting;
    private $previousDisplayErrors;

    /** @var ErrorReportingConfig */
    public $moduleConfig;

    public function init()
    {
        $this->app->configs->setDefaultConfig($this->id, new ErrorReportingConfig());

        $this->moduleConfig = $this->app->configs->getConfig($this->id);
        $this->moduleConfig->file = $this->app->parseUri($this->moduleConfig->file);

        if ($this->moduleConfig->mode != ErrorReportingModule::MODE_AUTO) {
            $this->app->events->join('stopErrorReporting', array($this, 'onChangeErrorReporting'));
            $this->app->events->join('startErrorReporting', array($this, 'onChangeErrorReporting'));
            $this->app->events->join('emergencyShutdown', array($this, 'onEmergencyShutdown'));

            $this->addRoute('', 'Pages\ErrorReportingPage');

            $this->app->events->join('logException', array($this, 'onLogException'));

            $this->startUpReporting();
        }
    }

    public function onEmergencyShutdown($e)
    {
        $this->suppressErrors = true;
    }

    /** @param \Cyantree\Grout\Event\Event $e */
    public function onChangeErrorReporting($e)
    {
        $this->suppressErrors = $e->type == 'stopErrorReporting';
    }

    public function onShutdown()
    {
        if (!self::$started) {
            return;
        }

        $error = error_get_last();

        if ($error === null) {
            return;
        }

        $se = new ScriptError($error['type'], $error['message']);
        $se->file = $error['file'];
        $se->line = $error['line'];
        $se->terminate = true;

        if (PHP_VERSION >= '5.3.6') {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        } else {
            $backtrace = debug_backtrace(false);
        }

        $ic = count($backtrace);
        for ($i = 1; $i < $ic; $i++) {
            $im = $backtrace[$i];
            if ($im && isset($im['line'])) {
                $se->stackTrace .= '#' . $i . ' ' . $im['file'] . ' (' . $im['line'] . ')' . chr(10);
            }
        }

        $this->processError($se);
    }

    public function onError($code, $message, $file, $line, $context)
    {
        // Error has been suppressed with @ sign
        if (error_reporting() === 0) {
            return;
        }

        if ($this->moduleConfig->convertErrorsToExceptions) {
            ErrorWrapper::onError($code, $message, $file, $line, $context);
            return;
        }

        $se = new ScriptError($code, $message);
        $se->file = $file;
        $se->line = $line;
        $se->context = $context;

        if (PHP_VERSION >= '5.3.6') {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        } else {
            $backtrace = debug_backtrace(false);
        }

        $ic = count($backtrace);
        for ($i = 1; $i < $ic; $i++) {
            $im = $backtrace[$i];
            if ($im && isset($im['line'])) {
                $se->stackTrace .= '#' . $i . ' ' . $im['file'] . ' (' . $im['line'] . ')' . chr(10);
            }
        }

        $se->terminate = in_array($code, array(E_WARNING, E_ERROR, E_USER_WARNING, E_USER_ERROR, E_COMPILE_ERROR, E_COMPILE_WARNING));
        if (!$se->terminate && $this->moduleConfig->terminateNoticeError) {
            $se->terminate = $code & E_NOTICE || $code & E_USER_NOTICE;
        }
        if (!$se->terminate && $this->moduleConfig->terminateStrictError) {
            $se->terminate = $code & E_STRICT;
        }

        if (!$se->terminate && $this->moduleConfig->terminateDeprecationError) {
            $se->terminate = $code & E_DEPRECATED || $code & E_USER_DEPRECATED;
        }

        $this->processError($se);
    }

    /** @param $e Exception */
    public function onException($e)
    {
        $se = new ScriptError(get_class($e), $e->getMessage());
        $se->code = $e->getCode();
        $se->file = $e->getFile();
        $se->line = $e->getLine();
        $se->stackTrace = $e->getTraceAsString();
        $se->terminate = true;

        $this->processError($se);
    }

    public function reportError(ScriptError $e)
    {
        if ($this->app->currentTask) {
            $url = $this->app->url . $this->app->currentTask->request->url;
        } else {
            $url = $this->app->url . '[UNKNOWN-URL]';
        }

        if (isset($this->reportedErrorSignatures[$e->signature])) {
            return;
        }
        $this->reportedErrorSignatures[$e->signature] = true;

        $data =
            'URL: ' . $url . chr(10) .
            'Date: ' . date(DateTime::ISO8601, time()) . chr(10) . chr(10) .

            'Signature: ' . $e->signature . chr(10) .
            'Type: ' . $e->type . chr(10) .
            ($e->code ? ('Code: ' . $e->code . chr(10)) : '') .
            'Message: ' . $e->message . chr(10);

        if ($e->file) {
            $data .= 'File: ' . $e->file . chr(10);
        }
        if ($e->line) {
            $data .= 'Line: ' . $e->line . chr(10);
        }

        if ($this->app->currentTask) {
            if ($this->moduleConfig->includeIp) {
                $data .= 'IP: ' . $this->app->currentTask->request->server->get('REMOTE_ADDR') . chr(10);
            }

            if ($this->moduleConfig->includeUserAgent) {
                $data .= 'User-Agent: ' . $this->app->currentTask->request->server->asString('HTTP_USER_AGENT')->asInput(255)->value . chr(10);
            }
        }

        $data .= chr(10) . $e->stackTrace;

        $sendErrorMail = false;
        $errorReportingEnabled = true;
        $newErrorFileTimestamp = 0;

        if ($this->moduleConfig->email) {
            if ($this->errorFileTimestamp) {
                $newErrorFileTimestamp = $this->errorFileTimestamp;

            } else {
                if (file_exists($this->moduleConfig->file)) {
                    $newErrorFileTimestamp = filemtime($this->moduleConfig->file);

                } else {
                    $newErrorFileTimestamp = 0;
                }
            }

            if ($this->moduleConfig->emailEverySeconds == 0) {
                $sendErrorMail = true;
                $newErrorFileTimestamp = 0;

            } elseif (!$this->reportedErrors) {
                if (!file_exists($this->moduleConfig->file) || filesize($this->moduleConfig->file) == 0) {
                    $sendErrorMail = true;
                    $newErrorFileTimestamp = 0;

                } elseif ($this->moduleConfig->emailEverySeconds > 0
                    && (time() - $newErrorFileTimestamp) >= $this->moduleConfig->emailEverySeconds
                ) {
                    $sendErrorMail = true;
                    $newErrorFileTimestamp = 0;
                }
            }
        }

        $errorData = $data . chr(10) . '--' . chr(10) . chr(10);

        if ($this->moduleConfig->file) {
            $f = fopen($this->moduleConfig->file, 'a+');
            fseek($f, 0);

            if (fread($f, 8) != 'disabled') {
                $size = filesize($this->moduleConfig->file);

                if ($size > $this->moduleConfig->fileMaxSize) {
                    fseek($f, $this->moduleConfig->fileTruncateSize);

                    $data = fread($f, $size - $this->moduleConfig->fileTruncateSize);
                    ftruncate($f, 0);
                    fseek($f, 0);
                    fwrite($f, $data);

                } else {
                    fseek($f, $size);
                }

                fwrite($f, $errorData);

            } else {
                // Error reporting is disabled
                $errorReportingEnabled = false;
            }

            fclose($f);

            if ($newErrorFileTimestamp) {
                $this->errorFileTimestamp = $newErrorFileTimestamp;

                if ($errorReportingEnabled) {
                    touch($this->moduleConfig->file, $newErrorFileTimestamp);
                }

            } else {
                $this->errorFileTimestamp = time();
            }
        }

        if ($errorReportingEnabled && $sendErrorMail) {
            $subject = '[Error] ' . $this->app->getConfig()->projectTitle . ' (@' . $e->signature . ')';

            $text = 'An error occurred on your website "' . $this->app->getConfig()->projectTitle . '":' . chr(10) .
                $this->getUrl() . chr(10) . chr(10);

            if ($this->moduleConfig->emailEverySeconds == -1) {
                $text .= 'You will receive the next notification after you have cleared the error log.';

            } elseif ($this->moduleConfig->emailEverySeconds == 0) {
                $text .= 'You will receive the next notification when another error occurred.';

            } else {
                $text .= 'You will receive the next notification when an error occurred ' . $this->moduleConfig->emailEverySeconds . ' seconds after this incident.';
            }

            $text .= chr(10) . chr(10) .
                'Last occurred error:' . chr(10) . chr(10) .
                $data;

            $m = new Mail($this->moduleConfig->email, $subject, $text, null, $this->moduleConfig->emailSender);
            $this->app->events->trigger('mail', $m);
        }

        $this->reportedErrors = true;
    }

    /** @param $e ScriptError */
    public function processError($e)
    {
        // PHP changes working directory, so change it back
        chdir($this->app->path);

        $e->generateSignature();

        if ($e->suppress === null) {
            $e->suppress = $this->suppressErrors;
        }

        $this->filterError($e);

        $this->events->trigger('onError', $e);

        // Error should be reported
        if (!$e->suppress) {
            $this->reportError($e);
        }

        if ($e->terminate) {
            while (ob_get_level()) {
                ob_end_clean();
            }

            $this->events->trigger('onTerminate', $e);

            if ($this->app->currentTask && !$this->app->currentTask->page) {
                $this->app->currentTask->setPage(new ErrorReportingPage());
            }

            $this->app->emergencyShutdown($e);
        }
    }

    public function getErrorCache()
    {
        if (!$this->moduleConfig->file || !file_exists($this->moduleConfig->file)) {
            return null;
        }

        return file_get_contents($this->moduleConfig->file);
    }

    public function clearErrorCache()
    {
        if (!$this->moduleConfig->file || !file_exists($this->moduleConfig->file)) {
            return;
        }

        file_put_contents($this->moduleConfig->file, '');
    }

    private function startUpReporting()
    {
        $mode = $this->moduleConfig->mode;

        if ($mode === ErrorReportingModule::MODE_LOG) {
            $this->catchErrors();

        } elseif ($mode === ErrorReportingModule::MODE_SHOW) {
            $this->showErrors();
        }
    }

    private function showErrors()
    {
        $this->previousDisplayErrors = ini_set('display_errors', true);
        $this->previousErrorReporting = error_reporting(E_ALL);
    }

    private function catchErrors()
    {
        if (self::$started) {
            return;
        }

        self::$started = true;

        // Log previous error
        $error = error_get_last();
        if ($error['type']) {
            $e = new ScriptError($error['type'], $error['message']);
            $e->file = $error['file'];
            $e->line = $error['line'];
            $e->terminate = false;

            $this->processError($e);
        }

        set_exception_handler(array($this, 'onException'));
        register_shutdown_function(array($this, 'onShutdown'));
        set_error_handler(array($this, 'onError'));

        $this->previousErrorReporting = error_reporting(65535); // Unused error type to distinguish with @ suppressed errors
        $this->previousDisplayErrors = ini_set('display_errors', false);
    }

    private function filterError(ScriptError $error)
    {
        if ($this->moduleConfig->ignoreUploadSizeError
            && preg_match('/^POST Content-Length of [0-9]+ bytes exceeds the limit of [0-9]+ bytes$/', $error->message)
        ) {
            $error->suppress = true;
        }
    }

    public function destroy()
    {
        if (self::$started) {
            restore_exception_handler();
            restore_error_handler();

            error_reporting($this->previousErrorReporting);
            ini_set('display_errors', $this->previousDisplayErrors);
        }
    }

    public function onLogException(Event $e)
    {
        /** @var Exception $exception */
        $exception = $e->data;

        $se = new ScriptError($exception->getCode(), $exception->getMessage());
        $se->file = $exception->getFile();
        $se->line = $exception->getLine();
        $se->stackTrace = $exception->getTraceAsString();
        $se->generateSignature();

        $this->reportError($se);
    }
}
