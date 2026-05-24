<?php
$handler = set_error_handler(function() { return false; });
var_dump(gettype($handler));
if (is_array($handler)) {
    var_dump(get_class($handler[0]));
}
restore_error_handler();
