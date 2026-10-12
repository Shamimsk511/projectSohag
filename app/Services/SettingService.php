<?php

namespace App\Services;

use App\Enums\CashHandoverMode;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admin-editable switches stored in `settings`; a missing row means the default.
 */
class SettingService
{
    private const CASH_HANDOVER_MODE = 'cash_handover_mode';

    private const AUTO_CLOSE_DAYS = 'auto_close_days';

    public function __construct(private readonly AuditLogger $audit) {}

    public function cashHandoverMode(): CashHandoverMode
    {
        $value = Setting::query()->where('key', self::CASH_HANDOVER_MODE)->value('value');

        return CashHandoverMode::tryFrom((string) $value) ?? CashHandoverMode::Manual;
    }

    public function setCashHandoverMode(CashHandoverMode $mode, User $actor): void
    {
        $this->set(self::CASH_HANDOVER_MODE, $mode->value, $actor);
    }

    /**
     * Whether `days:close` closes past days every night. On unless an admin turned it off.
     */
    public function autoCloseDays(): bool
    {
        return Setting::query()->where('key', self::AUTO_CLOSE_DAYS)->value('value') !== '0';
    }

    public function setAutoCloseDays(bool $enabled, User $actor): void
    {
        $this->set(self::AUTO_CLOSE_DAYS, $enabled ? '1' : '0', $actor);
    }

    private function set(string $key, string $value, User $actor): void
    {
        DB::transaction(function () use ($key, $value, $actor): void {
            $setting = Setting::query()->where('key', $key)->lockForUpdate()->first();

            if ($setting === null) {
                $setting = Setting::create(['key' => $key, 'value' => $value, 'updated_by' => $actor->id]);
                $this->audit->created('setting.created', $setting, $actor);

                return;
            }

            if ($setting->value === $value) {
                return;
            }

            $setting->update(['value' => $value, 'updated_by' => $actor->id]);
            $this->audit->updated('setting.updated', $setting, $actor);
        });
    }
}
