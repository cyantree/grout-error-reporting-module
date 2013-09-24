cyantree Grout - ErrorReportingModule
=====================================

Changes
-------

### 0.1.0

-   **BREAKING:** Errors won't get converted to exceptions anymore. You have to make sure that your application doesn't use any try-catch-block where errors would be triggered. If you want to convert errors in a specific area to exceptions use the class "ErrorWrapper".

-   **BREAKING:** Configuration "emailAllErrors" has been removed. See "emailEverySeconds" for a more powerful replacement.

-   **FEATURE:** Configuration "emailEverySeconds" has been added. It can be used to configure a delay between two error notifications. E. g. you could be notified only once a day. "-1" turns only sends an email on the first error, "0" notifies about every error.

-   **FEATURE:** Commands prefixed by the @ sign will be ignored. This didn't work in the past.

### 0.0.2

-   **FIX:** Reporting mails couldn't be sent because the recipient was passed
    incorrectly

### 0.0.1

-   Initial commit
