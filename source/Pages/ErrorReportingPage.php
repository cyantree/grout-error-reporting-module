<?php
namespace Grout\Cyantree\ErrorReportingModule\Pages;

use Cyantree\Grout\App\Page;
use Cyantree\Grout\App\Types\ContentType;

use Cyantree\Grout\Tools\StringTools;
use Grout\Cyantree\ErrorReportingModule\ErrorReportingModule;

class ErrorReportingPage extends Page
{
    public function parseTask()
    {

        /** @var $m ErrorReportingModule */
        $m = $this->task->module;

        $mode = $this->request()->get->get('mode');
        $status = null;

        if ($mode == 'clear') {
            file_put_contents($m->moduleConfig->file, '');
            $status = 'All errors have been cleared.';

        } elseif ($mode == 'trigger') {
            trigger_error('A test error has been triggered.');
            $status = 'The test error has been triggered.';

        } elseif ($mode == 'toggle') {

            if (!is_file($m->moduleConfig->file)) {
                file_put_contents($m->moduleConfig->file, 'disabled');

                $status = 'Error reporting has been disabled.';

            } else {
                $f = fopen($m->moduleConfig->file, 'r+');
                if (fread($f, 8) == 'disabled') {
                    ftruncate($f, 0);

                    $status = 'Error reporting has been enabled.';

                } else {
                    ftruncate($f, 0);
                    fseek($f, 0);
                    fwrite($f, 'disabled');

                    $status = 'Error reporting has been disabled.';
                }

                fclose($f);
            }
        }

        $errors = is_file($m->moduleConfig->file) ? file_get_contents($m->moduleConfig->file) : '';

        if (substr($errors, 0, 8) == 'disabled') {
            $toggleLabel = 'Enable reporting';

        } else {
            $toggleLabel = 'Disable reporting';
        }

        $errors = StringTools::escapeHtml($errors);


        $content = <<<CNT
<!DOCTYPE html>
<body style="margin:0;padding:0">
<div style="position:fixed;width:100%;background:white;padding:10px;border-bottom:solid 1px black">
<a href="?">Show errors</a>
<a href="?mode=clear">Clear errors</a>
<a href="?mode=trigger">Trigger error</a>
<a href="?mode=toggle">{$toggleLabel}</a>
<br />
<strong>
{$status}
</strong>
</div>
<div style="padding-top: 100px;">
<pre>
{$errors}
</pre>
</div>
</body>
CNT;

        $this->task->response->postContent($content, ContentType::TYPE_HTML_UTF8);
    }
}