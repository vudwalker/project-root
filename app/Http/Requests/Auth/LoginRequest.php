<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Laravel標準のWebガードを使用し、activeユーザーだけを認証します。
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $authenticated = Auth::guard('web')->attempt([
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'status' => 'active',
        ]);

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが正しくありません。',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * メールアドレスの存在有無にかかわらず、同じ単位で試行回数を制限します。
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "ログイン試行回数が上限に達しました。{$seconds}秒後に再度お試しください。",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->string('email')->toString()).'|'.$this->ip(),
        );
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }
}
