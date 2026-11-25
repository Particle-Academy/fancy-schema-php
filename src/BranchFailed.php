<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

use RuntimeException;

/** @internal A probed oneOf / not branch failed; its errors are not recorded. */
final class BranchFailed extends RuntimeException {}
