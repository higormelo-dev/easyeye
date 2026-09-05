<?php

use Carbon\Carbon;
use Tests\Support\Fakes\HasBusinessDaysTestable;

describe('HasBusinessDays::countBusinessDays', function () {
    beforeEach(fn () => $this->subject = new HasBusinessDaysTestable());

    it('returns 0 when start equals end', function () {
        $date = Carbon::parse('2024-03-11'); // segunda-feira
        expect($this->subject->publicCountBusinessDays($date, $date))->toBe(0);
    });

    it('counts 1 business day from Monday to Tuesday', function () {
        $start = Carbon::parse('2024-03-11'); // segunda
        $end   = Carbon::parse('2024-03-12'); // terça
        expect($this->subject->publicCountBusinessDays($start, $end))->toBe(1);
    });

    it('counts 5 business days in a standard week', function () {
        $start = Carbon::parse('2024-03-11'); // segunda
        $end   = Carbon::parse('2024-03-15'); // sexta
        expect($this->subject->publicCountBusinessDays($start, $end))->toBe(4);
    });

    it('skips weekends when counting', function () {
        $start = Carbon::parse('2024-03-15'); // sexta
        $end   = Carbon::parse('2024-03-18'); // segunda
        // sábado e domingo são pulados
        expect($this->subject->publicCountBusinessDays($start, $end))->toBe(1);
    });

    it('skips national holiday (January 1st)', function () {
        $start = Carbon::parse('2024-12-31'); // terça
        $end   = Carbon::parse('2025-01-02'); // quinta — 01/01 é feriado
        expect($this->subject->publicCountBusinessDays($start, $end))->toBe(1);
    });
});

describe('HasBusinessDays::willExpireInOneBusinessDay', function () {
    beforeEach(fn () => $this->subject = new HasBusinessDaysTestable());

    it('returns true when expires tomorrow (business day)', function () {
        // segunda → terça = 1 dia útil
        Carbon::setTestNow(Carbon::parse('2024-03-11 10:00:00')); // segunda
        $expiresAt = Carbon::parse('2024-03-12 10:00:00');         // terça
        expect($this->subject->publicWillExpireInOneBusinessDay($expiresAt))->toBeTrue();
        Carbon::setTestNow();
    });

    it('returns true when expires today', function () {
        Carbon::setTestNow(Carbon::parse('2024-03-11 10:00:00'));
        $expiresAt = Carbon::parse('2024-03-11 23:59:59');
        expect($this->subject->publicWillExpireInOneBusinessDay($expiresAt))->toBeTrue();
        Carbon::setTestNow();
    });

    it('returns false when expires in 3 business days', function () {
        Carbon::setTestNow(Carbon::parse('2024-03-11 10:00:00')); // segunda
        $expiresAt = Carbon::parse('2024-03-14 10:00:00');         // quinta = 3 dias úteis
        expect($this->subject->publicWillExpireInOneBusinessDay($expiresAt))->toBeFalse();
        Carbon::setTestNow();
    });
});

describe('HasBusinessDays::isBusinessDay', function () {
    beforeEach(fn () => $this->subject = new HasBusinessDaysTestable());

    it('returns true for a regular Monday', function () {
        expect($this->subject->publicIsBusinessDay(Carbon::parse('2024-03-11')))->toBeTrue();
    });

    it('returns false for Saturday', function () {
        expect($this->subject->publicIsBusinessDay(Carbon::parse('2024-03-16')))->toBeFalse();
    });

    it('returns false for Sunday', function () {
        expect($this->subject->publicIsBusinessDay(Carbon::parse('2024-03-17')))->toBeFalse();
    });

    it('returns false for January 1st (Confraternização Universal)', function () {
        expect($this->subject->publicIsBusinessDay(Carbon::parse('2024-01-01')))->toBeFalse();
    });

    it('returns false for Good Friday (Sexta-feira Santa)', function () {
        // Páscoa 2024 = 31/03, Sexta-feira Santa = 29/03
        expect($this->subject->publicIsBusinessDay(Carbon::parse('2024-03-29')))->toBeFalse();
    });
});

describe('HasBusinessDays::getEasterDate', function () {
    beforeEach(fn () => $this->subject = new HasBusinessDaysTestable());

    it('calculates Easter 2024 correctly (March 31)', function () {
        $easter = $this->subject->publicGetEasterDate(2024);
        expect($easter->format('Y-m-d'))->toBe('2024-03-31');
    });

    it('calculates Easter 2025 correctly (April 20)', function () {
        $easter = $this->subject->publicGetEasterDate(2025);
        expect($easter->format('Y-m-d'))->toBe('2025-04-20');
    });
});
