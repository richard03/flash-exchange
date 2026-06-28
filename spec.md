# Flash Exchange — Specifikace aplikace

## 1. Přehled

Webová aplikace pro přímý přenos textů a souborů mezi PC a mobilním telefonem přes sdílený server. Uživatel na PC otevře relaci, mobilní telefon připojí naskenováním QR kódu. Přenos probíhá oběma směry bez nutnosti instalace aplikace.

**Princip „proveď a zapomeň":** Server funguje jako dočasná předávací stanice. Data existují na serveru jen tak dlouho, dokud je příjemce nepřevezme — poté se okamžitě mažou. Po skončení relace po sobě aplikace nezanechá nic.

---

## 2. Základní tok

```
PC otevře / → vygeneruje session ID → zobrazí QR kód + čeká
Mobil naskenuje QR kód → otevře URL s session ID v prohlížeči
Mobil zobrazí výběr akce (co a jakým směrem přenést)
Uživatel vybere akci → odesílatel uvidí formulář, příjemce uvidí čekací obrazovku
Odesílatel odešle data → příjemce je automaticky zobrazí → data se smažou ze serveru
```

Více uživatelů může provádět přenos současně — každý má vlastní `session_id` a relace jsou na sobě zcela nezávislé.

---

## 3. Relace (Session)

### Vytvoření relace
- PC otevře hlavní stránku (`/`)
- Server vygeneruje náhodné `session_id` (32 znakový hex token, kryptograficky bezpečný přes `random_bytes`)
- Metadata relace se uloží jako **jeden malý JSON soubor v systémovém temp adresáři** (`sys_get_temp_dir()`)
- URL pro mobil: `https://domena.cz/?s=<session_id>`

### Životní cyklus relace
```
waiting   – PC čeká na připojení mobilu (max 5 minut, pak smazána)
active    – oba klienti připojeni (timeout 10 minut od posledního požadavku)
closed    – relace ukončena nebo expirovala → temp soubor okamžitě smazán
```

### Smazání relace
Relace se smaže (včetně všech temp souborů přenosů) při:
- expiraci timeoutu
- explicitním ukončení jedním z klientů
- zjištění, že partner je déle než 30 sekund offline

---

## 4. Typy přenosu

| # | Směr | Typ | Odesílatel | Příjemce |
|---|------|-----|-----------|---------|
| A | PC → Mobil | Text | PC formulář | Mobil zobrazí text |
| B | Mobil → PC | Text | Mobil formulář | PC zobrazí text |
| C | PC → Mobil | Soubor | PC file input | Mobil odkaz ke stažení |
| D | Mobil → PC | Soubor | Mobil file input | PC odkaz ke stažení |

---

## 5. Polling mechanismus

Protože PHP hosting neumožňuje WebSocket, komunikace probíhá pravidelným **short polling**:

- Každý klient každou **1 sekundu** dotazuje endpoint `/api/poll.php`
- Server vrátí nová data, pokud jsou k dispozici
- **Okamžitě po odeslání dat příjemci je payload ze serveru smazán** (sémantika jednoho čtení)
- Stav připojení partnera je součástí každé odpovědi

### Čekání na soubor
- Poll nevrací binární obsah souboru, ale jen metadata (název, velikost, `transfer_id`)
- Příjemce si soubor stáhne samostatným GET požadavkem na `/api/file.php`
- Po úspěšném stažení (HTTP 200) je soubor ze serveru okamžitě smazán

---

## 6. API endpointy (PHP)

### `GET /` — Vstupní bod
- Pokud parametr `?s=` chybí → PC pohled, vygeneruje novou relaci
- Pokud `?s=<id>` přítomen → Mobile pohled, připojí se k relaci

### `POST /api/send.php`
Odešle text nebo soubor do relace.

Parametry (form-data):
```
session_id   string    povinné
role         pc|mobile povinné
type         text|file povinné
text         string    pro type=text
file         file      pro type=file (max 25 MB)
```

Odpověď:
```json
{ "ok": true, "transfer_id": "t_abc123" }
```

- Text se uloží do session JSON
- Soubor se uloží do systémového temp dir jako `fx_<transfer_id>.bin`, metadata do session JSON

### `GET /api/poll.php`
Vrátí stav relace a čekající data. **Po doručení dat příjemci je payload ze session JSON smazán.**

Parametry:
```
s       session_id
role    pc|mobile
```

Odpověď:
```json
{
  "partner_connected": true,
  "pending": {
    "type": "text",
    "from": "pc",
    "transfer_id": "t_abc123",
    "content": "Hello from PC"
  }
}
```

nebo pro soubor (obsah se neposílá, jen metadata):
```json
{
  "partner_connected": true,
  "pending": {
    "type": "file",
    "from": "mobile",
    "transfer_id": "t_def456",
    "filename": "foto.jpg",
    "filesize": 204800
  }
}
```

Pokud nic nečeká: `"pending": null`

### `GET /api/file.php`
Streamuje soubor příjemci a **po úspěšném odeslání ho okamžitě smaže**.

Parametry:
```
s            session_id
transfer_id  id přenosu
```

Odpověď: binární obsah souboru s hlavičkami `Content-Disposition: attachment`.

### `POST /api/close.php`
Ukončí relaci a smaže veškerá zbývající data (session JSON + případné temp soubory).

---

## 7. Dočasné uložení na serveru

### Filozofie
Server uchovává data **pouze po dobu přenosu** — od okamžiku odeslání do okamžiku přijetí. Žádná historie, žádné záznamy, žádná databáze.

### Co a kde se ukládá

| Typ dat | Umístění | Smazáno |
|---------|----------|---------|
| Metadata relace | `{sys_temp}/fx_{session_id}.json` | při ukončení/expiraci relace |
| Text přenosu | uvnitř session JSON | okamžitě po přečtení pollem příjemce |
| Soubor | `{sys_temp}/fx_{transfer_id}.bin` | okamžitě po stažení příjemcem |

`sys_get_temp_dir()` vrací systémový temp adresář (`/tmp` nebo ekvivalent na hostingu). OS a hosting ho pravidelně čistí automaticky, aplikace ho čistí aktivně.

### Formát session JSON (příklad aktivní relace s čekajícím souborem)
```json
{
  "session_id": "abc...",
  "created_at": 1700000000,
  "pc_last_seen": 1700000058,
  "mobile_last_seen": 1700000060,
  "pending": {
    "transfer_id": "t_def456",
    "from": "mobile",
    "type": "file",
    "filename": "foto.jpg",
    "filesize": 204800,
    "for_role": "pc"
  }
}
```

Po přečtení pollem PC se `pending` nastaví na `null`. Po stažení souboru se smaže i `fx_def456.bin`.

### Čištění (lazy cleanup)
Na začátku každého PHP requestu se prohledají temp soubory s prefixem `fx_`:
- session JSON starší než **15 minut** → smazat včetně případného bin souboru
- osiřelé bin soubory bez odpovídajícího session JSON → smazat

Bez cron jobu, bez databáze. Zátěž je minimální — prochází se jen soubory prefixem `fx_`.

### Omezení
- Maximální velikost souboru: **25 MB** (limit volitelný, závisí na `upload_max_filesize` hostingu)
- V relaci může čekat vždy jen **jeden** přenos najednou (odesílatel musí počkat, než příjemce převezme)
- Maximální doba existence souboru na serveru: **15 minut**

---

## 8. UI — PC pohled

PC prochází třemi fázemi podle toho, jakou akci zvolil uživatel na mobilu.

### Fáze 1: Čekám na mobil
```
┌─────────────────────────────────────────────┐
│  Flash Exchange                   [Nová relace] │
├─────────────────────────────────────────────┤
│                                             │
│  Naskenujte QR kód mobilem:                 │
│                                             │
│        ┌──────────┐                         │
│        │  QR KÓD  │   nebo zkopírujte URL: │
│        │          │   https://domena.cz/... │
│        └──────────┘                         │
│                                             │
│  Stav: ⏳ čekám na připojení mobilu…        │
│                                             │
└─────────────────────────────────────────────┘
```

### Fáze 2a: PC je odesílatel (mobil zvolil „chci přijmout")
```
┌─────────────────────────────────────────────┐
│  Flash Exchange            Mobil: ● připojen │
├─────────────────────────────────────────────┤
│  Odeslat na mobil                           │
│                                             │
│  ○ Text   ● Soubor                          │
│                                             │
│  [Vybrat soubor: foto.jpg    ]              │
│                                       [Odeslat] │
└─────────────────────────────────────────────┘
```

### Fáze 2b: PC je příjemce (mobil zvolil „chci odeslat")
```
┌─────────────────────────────────────────────┐
│  Flash Exchange            Mobil: ● připojen │
├─────────────────────────────────────────────┤
│                                             │
│  ⏳ Čekám na data z mobilu…                 │
│                                             │
└─────────────────────────────────────────────┘
```

### Fáze 3: Data přijata
```
┌─────────────────────────────────────────────┐
│  Flash Exchange                   [Nová relace] │
├─────────────────────────────────────────────┤
│  Přijato z mobilu:                          │
│                                             │
│  [  Stáhnout dokument.pdf (1,2 MB)  ]       │
│                                             │
│  (soubor dostupný jen nyní – po stažení     │
│   bude ze serveru smazán)                   │
└─────────────────────────────────────────────┘
```

---

## 9. UI — Mobilní pohled

Mobil jako jediný zobrazuje výběr akce — tím určuje role obou stran.

### Fáze 1: Výběr akce (ihned po načtení QR)
```
┌─────────────────────────┐
│  Flash Exchange          │
│  PC: ● připojeno         │
├─────────────────────────┤
│  Co chcete přenést?     │
│                         │
│  Z MOBILU NA PC         │
│  [ Odeslat text       ] │
│  [ Odeslat soubor     ] │
│                         │
│  Z PC NA MOBIL          │
│  [ Přijmout text      ] │
│  [ Přijmout soubor    ] │
└─────────────────────────┘
```

### Fáze 2a: Mobil je odesílatel
```
┌─────────────────────────┐
│  ← Zpět                 │
│  Odeslat text na PC     │
├─────────────────────────┤
│                         │
│  ┌─────────────────┐    │
│  │                 │    │
│  │  (textarea)     │    │
│  │                 │    │
│  └─────────────────┘    │
│                         │
│  [      Odeslat      ]  │
└─────────────────────────┘
```

### Fáze 2b: Mobil je příjemce (PC odesílá)
```
┌─────────────────────────┐
│  Flash Exchange          │
├─────────────────────────┤
│                         │
│  ⏳ Čekám na data z PC… │
│                         │
└─────────────────────────┘
```

### Fáze 3: Data přijata na mobilu
```
┌─────────────────────────┐
│  Flash Exchange          │
├─────────────────────────┤
│  Přijato z PC:          │
│                         │
│  "Zde je text který     │
│   poslal PC uživatel."  │
│                         │
│  [ Kopírovat ]          │
│                         │
│  [ Nový přenos ]        │
└─────────────────────────┘
```

---

## 10. Bezpečnost

| Hrozba | Ochrana |
|--------|---------|
| Uhodnutí session ID | 128bitový náhodný token (`random_bytes`) |
| Přístup cizího klienta | Max. 2 role (pc, mobile) na relaci; třetí připojení odmítnuto |
| XSS | Výstupy escapovány přes `htmlspecialchars`; texty přenosu vloženy jako textový uzel DOM |
| Path traversal u souborů | Soubory ukládány jako `fx_{transfer_id}.bin` — název je GUID, ne uživatelský vstup |
| CSRF | `session_id` v těle POST požadavku jako implicitní token |
| Velké soubory | Limit 25 MB v PHP i na úrovni `.htaccess` |
| Expozice temp souborů | `sys_get_temp_dir()` leží mimo web root; přístup jen přes `file.php` |
| Opakované stažení | Soubor smazán po prvním úspěšném stažení; druhý pokus vrátí 404 |
| HTTPS | Certifikát povinný; HTTP přesměrováno na HTTPS |

---

## 11. Technologie

| Vrstva | Technologie |
|--------|------------|
| Backend | PHP 8.x |
| Úložiště | Systémový temp dir (`sys_get_temp_dir()`); bez databáze, bez trvalých souborů |
| Frontend | Vanilla JS, HTML5, CSS3 (žádný framework) |
| QR kód | JS knihovna `qrcode.js` — generování na straně klienta, nic se neposílá na server |
| Polling | `setInterval` + `fetch` každou 1 s |
| Hosting | Sdílený PHP hosting s HTTPS |

MySQL **není potřeba**. Bylo by relevantní pouze pokud hosting zakazuje zápis do temp adresáře — to je neobvyklá konfigurace a řeší se u poskytovatele.

---

## 12. Adresářová struktura projektu

```
/
├── index.php              – vstupní bod (PC i mobile view)
├── api/
│   ├── send.php           – příjem přenosu (text nebo soubor)
│   ├── poll.php           – polling; vrátí a smaže čekající payload
│   ├── file.php           – stažení souboru; smaže ho po odeslání
│   └── close.php          – ukončení relace, smaže vše
├── lib/
│   ├── Session.php        – read/write/delete session JSON z temp dir
│   ├── Transfer.php       – logika přenosů a mazání payloadů
│   └── Cleanup.php        – lazy cleanup starých fx_* souborů
├── assets/
│   ├── app.js             – frontend logika (polling, formuláře, QR)
│   └── style.css
└── .htaccess              – HTTPS redirect, security headers, upload limit
```

Žádný `/sessions/` adresář v projektu — vše jde do systémového temp.

