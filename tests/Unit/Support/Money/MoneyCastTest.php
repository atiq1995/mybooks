<?php

declare(strict_types=1);

use App\Support\Money\MoneyCast;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * A throwaway model with a money column, so the cast can be exercised without
 * a database.
 */
final class MoneyCastFixture extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['total' => MoneyCast::class.':currency'];
    }
}

it('reads a numeric string into Money at storage scale', function (): void {
    $model = new MoneyCastFixture;
    $model->setRawAttributes(['currency' => 'PKR', 'total' => '117100.0000']);

    $money = $model->total;

    expect($money)->toBeInstanceOf(Money::class)
        ->and($money->getCurrency()->getCurrencyCode())->toBe('PKR')
        ->and((string) $money->getAmount())->toBeDecimal('117100.0000');
});

it('writes Money back as a decimal string, never a float', function (): void {
    $model = new MoneyCastFixture(['currency' => 'PKR']);
    $model->total = Money::of('1234.5', 'PKR');

    $stored = $model->getAttributes()['total'];

    expect($stored)->toBeString()->toBeDecimal('1234.5000');
});

it('accepts decimal strings and BigDecimal directly', function (): void {
    $model = new MoneyCastFixture(['currency' => 'PKR']);

    $model->total = '0.1';
    expect($model->getAttributes()['total'])->toBeDecimal('0.1000');

    $model->total = BigDecimal::of('0.2');
    expect($model->getAttributes()['total'])->toBeDecimal('0.2000');
});

it('refuses a float outright', function (): void {
    $model = new MoneyCastFixture(['currency' => 'PKR']);

    // 0.1 + 0.2 is the canonical demonstration of why this rule exists.
    $model->total = 0.1 + 0.2;
})->throws(InvalidArgumentException::class, 'Refusing to store a float');

it('refuses a currency mismatch between the value and the record', function (): void {
    $model = new MoneyCastFixture(['currency' => 'PKR']);

    $model->total = Money::of('100', 'USD');
})->throws(InvalidArgumentException::class, 'Currency mismatch');

it('refuses to read money when the currency column is missing', function (): void {
    $model = new MoneyCastFixture;
    $model->setRawAttributes(['total' => '100.0000']);

    $model->total;
})->throws(InvalidArgumentException::class, 'needs currency column');

it('preserves four decimal places through a round trip', function (): void {
    // A tax component that does not land on a whole minor unit must survive
    // storage intact: rounding happens at presentation, not persistence.
    $model = new MoneyCastFixture(['currency' => 'PKR']);
    $model->total = '17099.9999';

    $model->setRawAttributes($model->getAttributes());

    expect((string) $model->total->getAmount())->toBeDecimal('17099.9999');
});
