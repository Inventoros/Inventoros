<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A plugin's deactivate or uninstall code failed, but the state change itself
 * (deactivation or deletion) still went through. Callers report it as a
 * warning rather than an error.
 */
final class PluginHookFailed extends RuntimeException {}
