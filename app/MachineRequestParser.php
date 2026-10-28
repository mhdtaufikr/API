<?php

declare (strict_types=1);
final class MachineRequestParser
{
    public const PROFILE = 'observed-v1';
    public function parse(array $input): array
    {
        if (array_diff(array_keys($input), ['cardno', 'd', 'h', 'm'])) {
            compat_throw(new InvalidArgumentException('Unexpected request parameter.'));
        }
        foreach (['cardno', 'd', 'h', 'm'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key]) || $input[$key] === '') {
                compat_throw(new InvalidArgumentException('Missing or invalid parameter: ' . $key));
            }
        }
        // Actual terminals have been observed using both 0002T and 0002t.
        // Preserve the raw message; the letter remains an opaque literal.
        if (!preg_match('/\A(?<terminal>[0-9]{3})0002[Tt](?<card>[0-9]{4})(?<stamp>[0-9]{14})(?<direction>[0-9]{2})\z/D', $input['cardno'], $parts)) {
            compat_throw(new InvalidArgumentException('Message does not match the observed-v1 protocol profile.'));
        }
        if ($input['m'] !== $parts['terminal']) {
            compat_throw(new InvalidArgumentException('GET terminal does not match embedded terminal.'));
        }
        $zone = new DateTimeZone(env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta'));
        $time = DateTimeImmutable::createFromFormat('!YmdHis', $parts['stamp'], $zone);
        if (!$time || compat_datetime_errors() || $time->format('YmdHis') !== $parts['stamp']) {
            compat_throw(new InvalidArgumentException('Invalid embedded date or time.'));
        }
        if (!preg_match('/\A[0-9]{4}\/[0-9]{2}\/[0-9]{2}\z/D', $input['d']) || $input['d'] !== $time->format('Y/m/d')) {
            compat_throw(new InvalidArgumentException('GET date does not match embedded date.'));
        }
        if (!preg_match('/\A[0-9]{2}:[0-9]{2}\z/D', $input['h']) || $input['h'] !== $time->format('H:i')) {
            compat_throw(new InvalidArgumentException('GET time does not match embedded hour and minute.'));
        }
        if ($time > new DateTimeImmutable('+5 minutes', $zone)) {
            compat_throw(new InvalidArgumentException('Future transaction exceeds the five minute clock tolerance.'));
        }
        return ['card_number' => $parts['card'], 'terminal_id' => $parts['terminal'], 'direction_code' => $parts['direction'], 'event_datetime' => $time->format('c'), 'raw_message' => $input['cardno'], 'protocol_profile' => self::PROFILE, 'event_hash' => hash('sha256', implode('|', [self::PROFILE, $parts['terminal'], $parts['card'], $time->format('c'), $parts['direction']]))];
    }
}
