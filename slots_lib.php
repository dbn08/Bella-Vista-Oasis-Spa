<?php
/**
 * Bella Vista Oasis Spa - shared time-slot availability logic.
 *
 * book.php (live ?slots= check) and save_booking.php (final check on submit)
 * BOTH use these functions, so the two can never disagree about what is booked.
 *
 * Include this AFTER config.php:  require_once 'slots_lib.php';
 */

// The spa runs on Philippine time. If the server is still on PHP's default (UTC),
// date('Y-m-d') / date('H:i') would not match what customers see on their screens.
if (date_default_timezone_get() === 'UTC') {
    date_default_timezone_set('Asia/Manila');
}

const BV_SLOTS              = ['09:00', '10:30', '12:00', '13:30', '15:00', '16:30'];
const BV_HOLD_HOURS         = 24; // unpaid cash bookings are released after this many hours
const BV_OPEN_AREA_CAPACITY = 3;  // how many bookings the Open Treatment Area takes per slot

/** "9:00", "09:00:00", "09:00" -> "09:00" */
function bv_slot_hhmm(string $t): string
{
    return preg_match('/^(\d{1,2}):(\d{2})/', trim($t), $m)
        ? str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2]
        : trim($t);
}

/** Cancel unpaid "pay at the store" bookings that were never settled within the hold window. */
function bv_slot_release_unpaid(PDO $pdo): void
{
    try {
        $pdo->exec("UPDATE appointments SET status = 'Cancelled'
                    WHERE status = 'Pending' AND payment_method = 'cash' AND payment_status = 'unpaid'
                      AND created_at < (NOW() - INTERVAL " . (int)BV_HOLD_HOURS . " HOUR)");
    } catch (Throwable $e) {
        error_log('bv_slot_release_unpaid: ' . $e->getMessage());
    }
}

/**
 * List of "HH:MM" slots that are already taken on $date.
 * $roomId > 0  -> private room: a slot is taken if ANY active booking uses it.
 * $roomId = 0  -> Open Treatment Area: a slot is taken once it holds BV_OPEN_AREA_CAPACITY bookings.
 * Throws on database errors so the caller can report them (never silently "all available").
 */
function bv_slot_booked_list(PDO $pdo, string $date, int $roomId): array
{
    $out = [];
    if ($roomId > 0) {
        $st = $pdo->prepare("SELECT TIME_FORMAT(appointment_time, '%H:%i') AS slot
                             FROM appointments
                             WHERE appointment_date = ? AND room_id = ?
                               AND status NOT IN ('Cancelled', 'Rejected', 'Declined')");
        $st->execute([$date, $roomId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = bv_slot_hhmm((string)$row['slot']);
        }
        return array_values(array_unique($out));
    }

    $st = $pdo->prepare("SELECT TIME_FORMAT(appointment_time, '%H:%i') AS slot, COUNT(*) AS cnt
                         FROM appointments
                         WHERE appointment_date = ? AND (room_id IS NULL OR room_id = 0)
                           AND status NOT IN ('Cancelled', 'Rejected', 'Declined')
                         GROUP BY slot");
    $st->execute([$date]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int)$row['cnt'] >= BV_OPEN_AREA_CAPACITY) {
            $out[] = bv_slot_hhmm((string)$row['slot']);
        }
    }
    return array_values(array_unique($out));
}

/**
 * Is this single slot taken? Same rules as bv_slot_booked_list().
 * Pass $forUpdate = true INSIDE a transaction to lock the rows and prevent two
 * customers from grabbing the same slot at the same moment.
 */
function bv_slot_is_taken(PDO $pdo, string $date, string $hhmm, int $roomId, bool $forUpdate = false): bool
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $hhmm = bv_slot_hhmm($hhmm);

    if ($roomId > 0) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM appointments
                             WHERE appointment_date = ? AND TIME_FORMAT(appointment_time, '%H:%i') = ?
                               AND room_id = ? AND status NOT IN ('Cancelled', 'Rejected', 'Declined')" . $lock);
        $st->execute([$date, $hhmm, $roomId]);
        return (int)$st->fetchColumn() > 0;
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM appointments
                         WHERE appointment_date = ? AND TIME_FORMAT(appointment_time, '%H:%i') = ?
                           AND (room_id IS NULL OR room_id = 0)
                           AND status NOT IN ('Cancelled', 'Rejected', 'Declined')" . $lock);
    $st->execute([$date, $hhmm]);
    return (int)$st->fetchColumn() >= BV_OPEN_AREA_CAPACITY;
}
