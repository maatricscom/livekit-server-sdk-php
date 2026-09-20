<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\ImageOutput;
use LiveKit\Proto\SegmentedFileOutput;
use LiveKit\Proto\StreamOutput;

/**
 * Supplies several outputs to one egress request. Passing this object fills only the plural
 * *_outputs arrays; the deprecated singular `output` oneof is left unset.
 */
final readonly class EncodedOutputs
{
    public function __construct(
        public ?EncodedFileOutput $file = null,
        public ?StreamOutput $stream = null,
        public ?SegmentedFileOutput $segments = null,
        public ?ImageOutput $images = null,
    ) {
    }
}
