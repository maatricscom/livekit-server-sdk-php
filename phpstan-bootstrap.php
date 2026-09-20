<?php

declare(strict_types=1);

/**
 * protobuf v5 renamed Google\Protobuf\Internal\RepeatedField to Google\Protobuf\RepeatedField and
 * kept the old name alive only as a `class_alias()` registered at the bottom of RepeatedField.php —
 * it is never declared as a real class, so nothing can autoload it under its old name until the real
 * class has been loaded at least once (see google/protobuf src/Google/Protobuf/RepeatedField.php).
 *
 * Our generated src/Proto/*.php getters still carry `@return \Google\Protobuf\Internal\RepeatedField`
 * docblocks from before that rename. Without this bootstrap, PHPStan tries to reflect that class name
 * before the alias exists, finds no matching interfaces, and reports every iterator_to_array()/
 * assertCount() call on a repeated field as a type mismatch — even though the value really is a
 * Google\Protobuf\RepeatedField (Countable, IteratorAggregate) at runtime.
 *
 * Forcing the real class to load here registers the alias before analysis starts, so PHPStan resolves
 * Internal\RepeatedField to its actual ancestry instead of an unknown class. This corrects stale type
 * information; it does not suppress or ignore anything.
 *
 * Google\Protobuf\Internal\MapField did not move and needs no equivalent treatment.
 */
class_exists(\Google\Protobuf\RepeatedField::class);
