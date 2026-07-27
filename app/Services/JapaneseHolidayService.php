<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * ローカルCSVから日本の祝日を読み込むサービスです。
 *
 * 画面側へは祝日名を渡さず、日付の判定結果だけを返します。
 * 将来はこのクラスをデータベース参照へ置き換えられます。
 */
class JapaneseHolidayService
{
    /**
     * 同じリクエスト内でCSVを何度も読み込まないための簡易キャッシュです。
     *
     * @var array<string, string>|null
     */
    private ?array $holidays = null;

    /**
     * CSVの祝日一覧を日付 => 祝日名の形で読み込みます。
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->holidays !== null) {
            return $this->holidays;
        }

        $path = storage_path('app/data/japanese-holidays.csv');

        if (! is_readable($path)) {
            Log::warning('祝日CSVを読み込めませんでした。', ['path' => $path]);

            return $this->holidays = [];
        }

        $holidays = [];
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            Log::warning('祝日CSVを開けませんでした。', ['path' => $path]);

            return $this->holidays = [];
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 1) {
                continue;
            }

            $date = trim((string) $row[0]);

            // ヘッダー行や不正な日付は安全に無視します。
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $holidays[$date] = trim((string) ($row[1] ?? ''));
        }

        fclose($handle);

        return $this->holidays = $holidays;
    }

    public function isHoliday(string $date): bool
    {
        return array_key_exists($date, $this->all());
    }
}
