<?php
namespace Grout\Cyantree\ErrorReportingModule\Types;

class ScriptError
{
    public $terminate = false;
    public $suppress = null;

    public $code;
    public $type;
    public $message;
    public $line;
    public $file;
    public $context;

    public $stackTrace;

    public $signature;

    public function __construct($type = null, $message = null)
    {
        $this->type = $type;
        $this->message = $message;
    }

    public function generateSignature()
    {
        $this->signature = md5($this->type . $this->message . $this->file . $this->line . $this->stackTrace);
    }
}
