#!/usr/bin/env python3
"""
Telegram userbot 互動登入：產生 session 檔。

⚠ **這支是人工跑一次的**，不是排程用的。撥號那支（`tg_call.py`）刻意不做
登入 —— 排程不該停下來等人輸入驗證碼，所以它找不到 session 就直接失敗。

被 `php artisan telegram:call-login` 包起來呼叫；也可以直接跑：

    TG_API_ID=… TG_API_HASH=… TG_SESSION=/path/to/session python3 tools/tg_login.py

## 會問你三件事

1. **手機號碼** —— 要登入的那個 **userbot 帳號**的號碼，國際格式 `+8869…`
   （⚠ 不是申請 api_id 那個帳號的號碼，如果你用了不同帳號）
2. **驗證碼** —— 發到那個帳號的 **Telegram App 裡**（不是簡訊）
3. **兩步驗證密碼** —— 只有開了才會問
"""

import os
import sys

try:
    from telethon.sync import TelegramClient
except ImportError:
    sys.exit('請先安裝 telethon：pip3 install telethon')


def main():
    api_id = os.environ.get('TG_API_ID')
    api_hash = os.environ.get('TG_API_HASH')
    session = os.environ.get('TG_SESSION')

    if not api_id or not api_hash or not session:
        sys.exit('缺少 TG_API_ID / TG_API_HASH / TG_SESSION')

    # session 檔會建在這裡，目錄不存在的話 Telethon 不會自己建
    directory = os.path.dirname(session)

    if directory and not os.path.isdir(directory):
        os.makedirs(directory, exist_ok=True)
        print(f'已建立目錄：{directory}')

    with TelegramClient(session, int(api_id), api_hash) as client:
        me = client.get_me()

        print()
        print('=' * 56)
        print(f'登入成功：{me.first_name}（@{me.username}，id={me.id}）')
        print(f'session 已存到：{session}.session')
        print('=' * 56)
        print()
        print('⚠ 這個檔等同那個帳號的密碼，不要外流、不要進版控。')


if __name__ == '__main__':
    main()
