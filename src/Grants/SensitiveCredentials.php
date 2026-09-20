<?php

declare(strict_types=1);

namespace LiveKit\Grants;

use LiveKit\Proto\AutoTrackEgress;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\ImageOutput;
use LiveKit\Proto\RoomConfiguration;
use LiveKit\Proto\SegmentedFileOutput;

/**
 * Finds storage credentials hiding in a room configuration.
 *
 * A room configuration travels inside an access token, and an access token is
 * handed to a client. Anyone holding it can read it: a JWT is signed, not
 * encrypted. So an egress configuration carrying an S3 secret, a GCP service
 * account, an Azure account key or a stream key does not stay on the server —
 * it is published to whoever the token was minted for.
 *
 * LiveKit's own Go SDK refuses to sign such a token unless the caller opts in,
 * and this is the same check, field for field — with one addition. Go walks a
 * room's image outputs but its type switch has no case for them, so an S3 secret
 * on an image output passes. It leaks the same way as any other, so it is checked
 * here. Being stricter can only refuse a token that would have published a
 * credential, and the opt-out is there for anyone who means to.
 */
final class SensitiveCredentials
{
    /** Whether $config carries anything that must not be handed to a token holder. */
    public static function presentIn(?RoomConfiguration $config): bool
    {
        $egress = $config?->getEgress();

        if ($egress === null) {
            return false;
        }

        $participant = $egress->getParticipant();

        if ($participant !== null
            && (self::inOutputs($participant->getFileOutputs()) || self::inOutputs($participant->getSegmentOutputs()))
        ) {
            return true;
        }

        $room = $egress->getRoom();

        if ($room !== null) {
            // A stream output carries the stream key itself, so there is no field to
            // inspect: its presence is the problem.
            if (count($room->getStreamOutputs()) > 0) {
                return true;
            }

            if (self::inOutputs($room->getFileOutputs())
                || self::inOutputs($room->getSegmentOutputs())
                || self::inOutputs($room->getImageOutputs())
            ) {
                return true;
            }
        }

        return self::inOutput($egress->getTracks());
    }

    /** @param iterable<mixed> $outputs */
    private static function inOutputs(iterable $outputs): bool
    {
        foreach ($outputs as $output) {
            if (self::inOutput($output)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The upload destination is a oneof, and only one branch can be set. Each
     * carries its secret under a different name, which is why this cannot be one
     * lookup.
     */
    private static function inOutput(mixed $output): bool
    {
        if (!$output instanceof EncodedFileOutput
            && !$output instanceof SegmentedFileOutput
            && !$output instanceof AutoTrackEgress
            && !$output instanceof ImageOutput
        ) {
            return false;
        }

        return ($output->getS3()?->getSecret() ?? '') !== ''
            || ($output->getGcp()?->getCredentials() ?? '') !== ''
            || ($output->getAzure()?->getAccountKey() ?? '') !== ''
            || ($output->getAliOSS()?->getSecret() ?? '') !== '';
    }
}
