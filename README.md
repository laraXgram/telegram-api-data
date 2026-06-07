# telegram-api-data

Automated scraper for the [Telegram Bot API](https://core.telegram.org/bots/api). Parses the official docs and stores structured data as JSON — used by [laraXgram/laraquest](https://github.com/laraXgram/laraquest) to generate PHP code.

## How it works

1. GitHub Actions fetches `core.telegram.org/bots/api`
2. Parses all methods and types from the HTML
3. Saves `telegram-api.json` and commits it to this repo
4. Laraquest fetches the JSON from raw content and generates PHP files

## JSON structure

```json
{
  "scraped_at": "2026-06-07T00:00:00+00:00",
  "source": "https://core.telegram.org/bots/api",
  "methods": {
    "sendMessage": {
      "name": "sendMessage",
      "description": "...",
      "parameters": [
        { "name": "chat_id", "type": "Integer or String", "required": true, "description": "..." }
      ]
    }
  },
  "types": {
    "Message": {
      "name": "Message",
      "description": "...",
      "fields": [
        { "name": "message_id", "type": "Integer", "description": "..." }
      ]
    }
  }
}
```

## Schedule

Runs automatically every **Sunday at 00:00 UTC** via GitHub Actions.

## Raw content URL

```
https://raw.githubusercontent.com/laraXgram/telegram-api-data/main/telegram-api.json
```

## Run locally

```bash
php bin/scrape.php
```

Requires PHP 8.1+ with `ext-curl` and `ext-dom`.
