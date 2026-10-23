<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that is valid in form but breaks an admission rule (a full exam
 * session, a locked application…). Rendered as 422 { message }.
 */
class BusinessRuleException extends RuntimeException {}
