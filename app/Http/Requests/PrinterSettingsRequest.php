<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 15 §4・§6 PUT /settings/printer。host は IPv4 のプライベートアドレス（10/8・172.16/12・192.168/16）か
 * ホスト名（英数字・ハイフン・ドット）だけ。スキーム・パス・ポートは受け付けない（宛先の URL はフロントで組み立てる）
 */
class PrinterSettingsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $host = $this->input('host');
        if (is_string($host)) {
            $host = strtolower(trim($host));
            $this->merge(['host' => $host === '' ? null : $host]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'host' => ['present', 'nullable', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ! self::isAllowedHost($value)) {
                    $fail('プリンターの IP アドレスは 192.168.x.x などの店内のアドレスか、ホスト名で入力してください');
                }
            }],
            'paper_width' => ['required', 'integer', Rule::in([80, 58])],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['host' => 'プリンターの IP アドレス', 'paper_width' => '紙の幅'];
    }

    /** @return array{printer_host: string|null, printer_paper_width: int} */
    public function settings(): array
    {
        $host = $this->input('host');

        return [
            'printer_host' => is_string($host) ? $host : null,
            'printer_paper_width' => $this->integer('paper_width'),
        ];
    }

    public static function isAllowedHost(string $host): bool
    {
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host) === 1) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return false;
            }

            // NO_PRIV_RANGE で弾かれる＝プライベート。予約（0/8・127/8・169.254/16 など）は別に弾く
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        // ホスト名：ラベルは英数字とハイフン（先頭と末尾はハイフン不可）、ドット区切り。最後のラベルが数字だけのもの（IP の書き損じ）は不可
        return preg_match('/(^|\.)\d+$/', $host) !== 1 && preg_match('/^(?=.{1,100}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) === 1;
    }
}
