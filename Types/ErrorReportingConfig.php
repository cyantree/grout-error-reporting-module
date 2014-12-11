<?php
namespace Grout\Cyantree\ErrorReportingModule\Types;

use Grout\Cyantree\ErrorReportingModule\ErrorReportingModule;

class ErrorReportingConfig
{
    public $mode = ErrorReportingModule::MODE_LOG;

    public $file = 'data://errors.txt';
    public $fileMaxSize = 1000000;
    public $fileTruncateSize = 20000;

    public $email = null;

    public $emailSender = null;

    public $emailEverySeconds = 86400;

    public $includeIp = true;
    public $includeUserAgent = true;
    public $ignoreUploadSizeError = false;
    public $terminateNoticeError = false;
    public $terminateStrictError = false;
    public $terminateDeprecationError = false;
    public $convertErrorsToExceptions = false;
}
