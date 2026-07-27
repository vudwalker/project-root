<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * スタッフ画面のHTMLをブラウザへ保存させないためのミドルウェアです。
 *
 * シフト変更後に通常の再読み込みを行ったとき、
 * 古いHTMLではなくサーバーの最新情報を取得できるようにします。
 */
class PreventStaffPageCaching
{
    /**
     * Controllerが作成したレスポンスへ、キャッシュ禁止ヘッダーを追加します。
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 先にControllerまで処理を進め、完成したレスポンスを受け取ります。
        $response = $next($request);

        /*
         * no-store：レスポンス自体を保存しない
         * no-cache：再利用前に必ずサーバーへ確認する
         * must-revalidate：期限切れの内容をそのまま表示しない
         * max-age=0：受信直後から期限切れとして扱う
         */
        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, must-revalidate, max-age=0',
        );

        // HTTP/1.0形式の古いブラウザや中継キャッシュ向けの指定です。
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
