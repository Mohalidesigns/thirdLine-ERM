<?php

namespace App\Enums\Bcms;

/**
 * The closed vocabulary for `bcms_exercise_injects.delivery_channel`
 * (ADR 0023 Amendment 1, A1.3(c)).
 *
 * NOT CAST ON THE MODEL. The column is a free `string(20)` that no screen has
 * ever constrained, and casting a column that may already hold values outside
 * the enum is the enum-cast `ValueError` defect family this module has
 * already shipped once (`verification_status = 'failed'`). This enum is
 * enforced on WRITE ONLY — the inject Form Requests validate against
 * `InjectDeliveryChannel::values()` — never `->cast()`.
 *
 * THE `sim_` PREFIX IS DELIBERATE. It records in the data that the channel is
 * simulated, so a future inject-delivery integration cannot read a bare `sms`
 * value on this column as an instruction to actually send one. `in_room` is
 * the one value that names a real channel because it is one: the facilitator
 * or a scripted prop says the line out loud, nothing is dispatched.
 */
enum InjectDeliveryChannel: string
{
    case InRoom = 'in_room';
    case SimPhone = 'sim_phone';
    case SimEmail = 'sim_email';
    case SimSms = 'sim_sms';
    case SimMedia = 'sim_media';
    case SimSystem = 'sim_system';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::InRoom => 'In room',
            self::SimPhone => 'Simulated phone call',
            self::SimEmail => 'Simulated email',
            self::SimSms => 'Simulated SMS',
            self::SimMedia => 'Simulated media enquiry',
            self::SimSystem => 'Simulated system alert',
        };
    }
}
