#!/usr/bin/env python3
"""
Telegram userbot 響鈴 sidecar。

被 `App\\Services\\TelegramCallService` 以 Symfony Process 呼叫。
求助單催到升級門檻還沒人處理時，用它把當班人的手機叫響。

## 為什麼是「只響不說話」

Bot API 完全沒有語音通話能力；userbot 串音訊那一層（libtgvoip / pytgcalls
的一對一通話）多年沒人維護。但**「響」不需要音訊** ——
`phone.requestCall` 讓對方手機響，過 N 秒 `phone.discardCall`，
他看到一通未接來電。電話的價值就在它穿得過靜音與勿擾模式，而那只需要響。

該做什麼在 Telegram 訊息裡已經寫了十次，所以這通電話不需要內容。

## 輸出

一律印一行 JSON 到 stdout，呼叫端只讀那一行：

    {"ok": true,  "answered": false, "call_id": "123", "rang_seconds": 30}
    {"ok": false, "error": "USER_PRIVACY_RESTRICTED", "retryable": false}

⚠ `ok: true` 只代表**撥號請求被 Telegram 接受**。實測過對方手機確實會響
（2026-11-09），但這支程式本身無從證明那件事 —— 它拿不到對方的鈴聲狀態。

⚠ `answered` 僅供記錄，**不要拿它當「人已經處理」的判準** ——
接起來會立刻斷線（沒有音訊層），分不出「沒接」與「接了就斷」。
真正的判準是求助單自己的 status。

## 環境變數

    TG_API_ID       my.telegram.org 申請（⚠ 擋 IP 不是擋帳號，被擋時換手機熱點）
    TG_API_HASH
    TG_SESSION      session 檔路徑（不含 .session 副檔名）

⚠ session 必須**先人工跑一次互動登入**產生（要收驗證碼）。
這支程式不會、也不該在排程裡要求輸入驗證碼 —— 沒有 session 就直接失敗。
"""

import argparse
import hashlib
import json
import os
import secrets
import sys
import time

try:
    from telethon.sync import TelegramClient
    from telethon.errors import RPCError
    from telethon.tl.functions.phone import DiscardCallRequest, RequestCallRequest
    from telethon.tl.types import (
        InputPhoneCall,
        PhoneCallDiscardReasonHangup,
        PhoneCallProtocol,
    )
except ImportError:
    print(json.dumps({'ok': False, 'error': 'TELETHON_NOT_INSTALLED', 'retryable': False}))
    sys.exit(1)


# 這些錯誤重撥也不會好 —— 呼叫端據此決定要不要浪費剩下的撥號次數
PERMANENT_ERRORS = (
    'USER_PRIVACY_RESTRICTED',   # 對方「誰可以打電話給我」擋住了
    'PARTICIPANT_VERSION_OUTDATED',
    'CALL_PROTOCOL_FLAGS_INVALID',
    'USER_IS_BLOCKED',
    'PEER_ID_INVALID',
)


def fail(error, retryable=False, detail=None):
    """印一行失敗 JSON 然後結束。"""
    payload = {'ok': False, 'error': str(error), 'retryable': retryable}

    if detail:
        payload['detail'] = str(detail)[:200]

    print(json.dumps(payload, ensure_ascii=False))
    sys.exit(1)


def build_protocol():
    """
    通話協定宣告。

    我們不會走到音訊協商，但 `requestCall` 要求帶一組合法的 protocol，
    否則會被擋在參數檢查。版本範圍抄官方 client 用的那一組。
    """
    return PhoneCallProtocol(
        min_layer=65,
        max_layer=92,
        udp_p2p=True,
        udp_reflector=True,
        library_versions=['2.4.4'],
    )


def ring(client, target, seconds):
    """撥給 target、響 seconds 秒、掛掉。回傳要印出去的 dict。"""
    try:
        entity = client.get_input_entity(target)
    except (ValueError, TypeError) as error:
        # 找不到對象：沒設 username、或這個帳號沒跟他互動過
        fail('ENTITY_NOT_FOUND', retryable=False, detail=error)

    # g_a_hash 是 DH 第一步的 SHA256。我們不完成握手，所以給一組格式合法的隨機值
    g_a = secrets.token_bytes(256)

    try:
        result = client(RequestCallRequest(
            user_id=entity,
            random_id=secrets.randbelow(2 ** 31),
            g_a_hash=hashlib.sha256(g_a).digest(),
            protocol=build_protocol(),
            video=False,
        ))
    except RPCError as error:
        name = type(error).__name__
        code = getattr(error, 'message', '') or name
        retryable = not any(perm in str(code).upper() for perm in PERMANENT_ERRORS)

        fail(code, retryable=retryable, detail=error)

    call = getattr(result, 'phone_call', None)

    if call is None:
        fail('NO_CALL_IN_RESPONSE', retryable=True, detail=result)

    time.sleep(seconds)

    answered = False

    try:
        discarded = client(DiscardCallRequest(
            peer=InputPhoneCall(id=call.id, access_hash=call.access_hash),
            duration=0,
            reason=PhoneCallDiscardReasonHangup(),
            connection_id=0,
        ))
        # 對方按過接通的話，掛斷前的狀態會不是 waiting。僅供記錄，不是判準
        answered = 'Waiting' not in type(getattr(discarded, 'phone_call', discarded)).__name__
    except RPCError:
        # 掛斷失敗不影響結論：響過了就是響過了，Telegram 自己也會逾時收掉
        pass

    return {
        'ok': True,
        'answered': answered,
        'call_id': str(call.id),
        'rang_seconds': seconds,
    }


def main():
    parser = argparse.ArgumentParser(description='Telegram userbot 響鈴')
    parser.add_argument('target', help='@username、+886 手機、或 user id')
    parser.add_argument('--seconds', type=int, default=30, help='響幾秒後掛斷')
    args = parser.parse_args()

    api_id = os.environ.get('TG_API_ID')
    api_hash = os.environ.get('TG_API_HASH')
    session = os.environ.get('TG_SESSION')

    if not api_id or not api_hash or not session:
        fail('MISSING_CREDENTIALS')

    # ⚠ session 不存在就直接失敗，不要讓排程卡在等驗證碼的互動提示
    if not os.path.exists(session + '.session'):
        fail('SESSION_NOT_FOUND', detail=session + '.session')

    try:
        with TelegramClient(session, int(api_id), api_hash) as client:
            if not client.is_user_authorized():
                fail('SESSION_UNAUTHORIZED')

            print(json.dumps(ring(client, args.target, args.seconds), ensure_ascii=False))
    except SystemExit:
        raise
    except Exception as error:  # noqa: BLE001 — 任何例外都要變成一行 JSON
        fail(type(error).__name__, retryable=True, detail=error)


if __name__ == '__main__':
    main()
