# Migrazione del kernel a PHP 8 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** il kernel eZ Publish legacy di Opencontent (branch `php8`) gira senza errori fatali né deprecazioni del kernel su PHP 8.3, e resta compatibile con PHP 7.4.

**Architecture:** si parte dal nostro fork (`master` = `2020.1000.16`), si fa il merge di Netgen `ibexa-support`, si prendono con cherry-pick i fix recenti di Mugo, poi si correggono a mano i punti residui. Ogni correzione deve funzionare uguale su 7.4 e 8.3. La verifica avviene con il lint 7.4/8.3, con PHPStan in CI e con un avvio reale del CMS su PHP 8.3 (`php8-testenv/`).

**Tech Stack:** PHP 7.4 / 8.3, eZ Publish legacy, zetacomponents, PostgreSQL 10, Docker (`webdevops/php-nginx:8.3-alpine`), PHPStan 2.x, PHPCompatibility `dev-develop`.

**Spec:** analisi della scelta della base: [Base kernel per PHP 8](https://claude.ai/artifact/7cmgiu2j6UoLcWqQprsXWw). Questo piano registra anche il lavoro già fatto e le decisioni aperte.

## Global Constraints

- PHP supportato dal kernel: `^7.4 || ^8.1` (`composer.json`). Obiettivo di esercizio: 8.2 o 8.3.
- Nessuna sintassi esclusiva di PHP 8: niente tipi `mixed`, union type, `match`, nullsafe, argomenti con nome, promozione nel costruttore.
- Return type delle interfacce interne: si usa `#[\ReturnTypeWillChange]` sulla riga sopra il metodo, mai un tipo dichiarato.
- Firme con un parametro opzionale prima di uno obbligatorio: si toglie il default (PHP lo ignorava già). Mai aggiungere default alle firme: romperebbe le estensioni che ridefiniscono il metodo.
- Le nostre patch restano: eZURLAliasML (limite di profondità, niente reparenting inutile), formato del log con IP e identificatore, conversione eZTimestamp disattivata, `EZINI_*` in `ezini.php`, `composer.json` con nome `ezsystems/ezpublish-legacy` e `opencontent/ezjscore`.
- Nessuna funzionalità nuova che non serva a PHP 8: le funzionalità di Mugo e Netgen entrano solo con una decisione esplicita (vedi Decisioni aperte).
- Ogni file PHP toccato passa `php -l` con 7.4 e con 8.3.
- Messaggi di commit in inglese. Quando li scrive un agente finiscono con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Cache DFS dopo una cancellazione o uno spostamento:** dopo `delete()`, `fileMove()` o `move()` di un file in cluster, la lettura successiva di `metaData` deve ricaricare i metadati dal DB, non restituire `null` per sempre (Task 1).
2. **CLI con debug attivo:** uno script in `bin/php/` con `--debug` su PHP 8.3 non deve emettere deprecazioni per `fputs(null)` quando il report di debug è vuoto (Task 1).
3. **Template con `{for}` compilati:** con `TemplateCompile=enabled` i template compilati non devono contenere `${...}` (Task 1).
4. **Sessioni su DB:** con `Handler=ezpSessionHandlerDB` login e logout devono funzionare su 8.3. La correzione c'è (`31b11a6cd1`), ma il test d'uso va fatto (Task 3).
5. **Output HTML con apici singoli:** da PHP 8.1 `htmlspecialchars` converte anche `'`. Contenuti con apostrofi in attributi, RSS ed export XML vanno controllati (Task 4, dopo la decisione D1).

---

## Fatto

| Data | Cosa | Commit |
| --- | --- | --- |
| 2026-11-04 | Messaggi dei nostri commit riscritti in inglese (albero identico) e push di `php8` su GitHub | — |
| 2026-11-04 | Deprecazioni di firme e return type (27 return type con `#[\ReturnTypeWillChange]`, 15 firme), `count(false)` in ezoe | `1557b602fe` |
| 2026-11-04 | Quattro errori fatali di PHP 8: `session_module_name('user')`, `implode` invertito in OAuth, `crypt()` senza salt in LDAP, precedenza `.`/`-` nell'export | `31b11a6cd1` |
| 2026-11-04 | Sintassi `$str{$i}` nel datatype ISBN e in WebDAV | `14d59ebdd6` |
| 2026-11-04 | Revert di 4 cherry-pick fuori scopo: 2 README di Mugo, migrazione `data_text` solo MySQL 8, permesso obbligatorio su `ezjscnode` | `82192d94f2`, `9f79b581f7`, `daf598e801`, `f6349d0e6e` |
| 2026-11-04 | 32 cherry-pick puliti da Mugo (2023–2026): fix PHP 8.2/8.3, SameSite, deprecazioni in `ezdebug`, ecc. Elenco con `git log --grep 'cherry picked from' master..php8` | `5a29b7d2e6` … `5c5306bb5d` |
| 2026-11-04 | Merge di `netgen/ezpublish-legacy@ibexa-support` con 7 conflitti risolti tenendo le nostre patch | `f319c251e8` |
| 2026-11-04 | Avvio reale del CMS su PHP 8.3 con il kernel `php8`: home, backend, login e `openapi.json` rispondono 200, nessun errore fatale | — |
| 2026-11-03 | Scelta della base: Netgen e cherry-pick di Mugo, con analisi pubblicata | — |

Stato misurato dopo `1557b602fe` (analisi statica di `kernel/` e `lib/` con PHP 8.2):

| Controllo | `master` | `php8` |
| --- | --- | --- |
| File con errore fatale di sintassi | 4 | 0 |
| Errori PHPCompatibility | 25 | 5 (falsi positivi verificati) |
| Return type / firme deprecate | 62 | 0 |
| Proprietà dinamiche segnalate da PHPStan | 1.291 | 58 (quasi tutte nelle classi REST che estendono zetacomponents, da verificare a runtime) |

---

## Task 1: Deprecazioni a runtime nel kernel

Le ha trovate l'avvio reale (`var/log/deprecated.log`).

**Files:**
- Modify: `lib/eztemplate/classes/eztemplateforfunction.php:110`
- Modify: `kernel/private/classes/clusterfilehandlers/ezdfsfilehandler.php:1046,1220,1236`
- Modify: `kernel/classes/ezscript.php:422-424`

**Interfaces:**
- Consumes: niente.
- Produces: `eZDFSFileHandler::delete()`, `fileMove()` e `move()` resettano `$this->_metaData = null`, così il `__get('metaData')` successivo chiama `loadMetaData()`.

- [ ] **Step 1: Verificare le tre deprecazioni con PHP 8.3**

Run: `/Applications/MAMP/bin/php/php8.3.30/bin/php -d error_reporting=-1 -l lib/eztemplate/classes/eztemplateforfunction.php`
Expected: `Deprecated: Using ${var} in strings is deprecated` alla riga 110.

Run: `grep -n 'this->metaData = null' kernel/private/classes/clusterfilehandlers/ezdfsfilehandler.php`
Expected: tre righe (1046, 1220, 1236). Su PHP 8.2+ creano la proprietà dinamica `metaData`, che poi nasconde `__get`.

- [ ] **Step 2: `eztemplateforfunction.php:110` — `${uniqid}` diventa `{$uniqid}`**

Le due occorrenze stanno nella stringa `$modifyLoopCounterCode`. Il codice PHP generato deve restare identico.

- [ ] **Step 3: `ezdfsfilehandler.php` — `$this->metaData = null;` diventa `$this->_metaData = null;` nelle tre righe**

È la stessa correzione di Exponential. Cambia un comportamento anche su 7.4: dopo una cancellazione i metadati vengono ricaricati invece di restare `null` (Review Focus 1).

- [ ] **Step 4: `ezscript.php` — `fputs` solo se il report è una stringa**

Il valore di `eZDebug::printReport( false, $webOutput, true, … )` va in una variabile e si chiama `fputs( STDERR, $report )` solo se `is_string( $report )`. `printReport` restituisce `null` quando il debug è disattivo.

- [ ] **Step 5: Lint su 7.4 e 8.3**

Run: `for P in /Applications/MAMP/bin/php/php7.4.33/bin/php /Applications/MAMP/bin/php/php8.3.30/bin/php; do for f in lib/eztemplate/classes/eztemplateforfunction.php kernel/private/classes/clusterfilehandlers/ezdfsfilehandler.php kernel/classes/ezscript.php; do $P -d error_reporting=-1 -l $f; done; done`
Expected: sei righe `No syntax errors detected`, nessun `Deprecated`.

- [ ] **Step 6: Verifica nell'ambiente di prova**

Run: `KERNEL_REF=HEAD docs/superpowers/plans/php8-testenv/setup.sh`, poi `docker exec cmsphp8-app-1 sh -c 'cd /var/www/html && : > var/log/deprecated.log && php bin/php/ezcache.php --clear-all && php bin/php/ezcache.php --clear-all --debug >/dev/null 2>&1; grep -c "kernel/\|lib/" var/log/deprecated.log'`
Expected: `0`.

- [ ] **Step 7: Commit**

```bash
git add lib/eztemplate/classes/eztemplateforfunction.php kernel/private/classes/clusterfilehandlers/ezdfsfilehandler.php kernel/classes/ezscript.php
git commit -m "Fix: PHP 8.2 runtime deprecations in template for, DFS and ezscript"
```

## Task 2: Controlli PHP 8 in CI

Ferma le regressioni: ogni PR deve passare il lint su 7.4 e 8.3, e PHPStan non deve trovare errori nuovi rispetto alla baseline.

**Files:**
- Create: `.github/workflows/php-compat.yml`
- Create: `phpstan.neon.dist`
- Create: `phpstan-baseline.neon`

**Interfaces:**
- Consumes: niente.
- Produces: il job `php-compat` (lint con matrice `7.4`, `8.3`; PHPStan) che i task successivi usano come verifica.

- [ ] **Step 1: `phpstan.neon.dist`**

`level: 0`, `phpVersion: 80200`, `paths: [kernel, lib]`, `excludePaths: [*/tests/*]`, `scanDirectories: [extension]`, `includes: [phpstan-baseline.neon]`.

- [ ] **Step 2: Generare la baseline**

Run: `vendor/bin/phpstan analyse -c phpstan.neon.dist --generate-baseline phpstan-baseline.neon`
Expected: baseline scritta, circa 760 errori, soprattutto `class.notFound` di zetacomponents non installato. Poi `vendor/bin/phpstan analyse -c phpstan.neon.dist` deve dare `[OK] No errors`.

- [ ] **Step 3: Workflow `.github/workflows/php-compat.yml`**

Gira su `pull_request` e `push` verso `master` e `php8`. Il job `lint` usa la matrice `php: ['7.4', '8.3']` con `shivammathur/setup-php@v2` e lancia `find kernel lib extension bin cronjobs update -name '*.php' -not -path '*/tests/*' -print0 | xargs -0 -n1 php -l`. Il job `phpstan` usa PHP 8.3 e installa PHPStan con `composer global require phpstan/phpstan:^2.1`, perché le dipendenze del kernel non si installano senza `opencontent/ezjscore`. Fallisce se il lint stampa `error` o PHPStan trova errori fuori baseline.

- [ ] **Step 4: Verifica che il job rilevi una regressione**

Su un branch temporaneo aggiungere `$x = "a"{0};` in un file di `lib/` e aprire una PR di prova.
Expected: il job `lint (8.3)` fallisce e `lint (7.4)` passa. Poi chiudere la PR e cancellare il branch.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/php-compat.yml phpstan.neon.dist phpstan-baseline.neon
git commit -m "CI: lint on PHP 7.4/8.3 and PHPStan for PHP 8.2"
```

## Task 3: Test d'uso del CMS su PHP 8.3

Il lint non vede gli errori a runtime. Si usa il CMS come farebbe una redazione e si registrano gli errori trovati.

**Files:**
- Modify: questo piano, sezione Fatto e un nuovo task per ogni errore trovato.

**Interfaces:**
- Consumes: l'ambiente `php8-testenv/` (Task 1, Step 6), con utente `admin` / `changethispassword`.
- Produces: l'elenco degli errori a runtime, ciascuno con file e riga.

- [ ] **Step 1: Azzerare i log**

Run: `docker exec cmsphp8-app-1 sh -c 'cd /var/www/html/var/log && for f in *.log; do : > $f; done'`

- [ ] **Step 2: Percorsi da provare, in ordine**

1. Login al backend (`/backend/user/login`), poi logout.
2. Navigazione nell'albero dei contenuti e apertura di 5 nodi di classi diverse.
3. Modifica e pubblicazione di una notizia con immagine e testo ricco (ezoe).
4. Ricerca dal frontend e dal backend (Solr).
5. API: `http -a admin:changethispassword http://127.0.0.1:18080/api/openapi/novita/notizie Host:opencity.localtest.me`.
6. Cron: `docker exec cmsphp8-app-1 php runcronjobs.php -s backend`.
7. Con `EZINI_site__Session__Handler=ezpSessionHandlerDB` riavviare l'app e ripetere il punto 1 (Review Focus 4).

- [ ] **Step 3: Raccogliere gli errori**

Run: `docker exec cmsphp8-app-1 sh -c 'cd /var/www/html/var/log && cat error.log warning.log deprecated.log' | grep -E 'PHP: E_|Fatal|Uncaught' | sort | uniq -c | sort -rn`, più `docker logs cmsphp8-app-1 2>&1 | grep 'PHP message'`.
Expected: nessun `Fatal` o `Uncaught`. Ogni riga che riguarda `kernel/` o `lib/` diventa un task in questo piano; quelle sulle estensioni vanno nella sezione Fuori da questo repo.

## Task 4: Flag di `htmlspecialchars` (dopo la decisione D1)

Da PHP 8.1 il default è `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401`. Su 7.4 era `ENT_COMPAT | ENT_HTML401`. PHPCompatibility segnala 42 chiamate senza flag esplicito, fra kernel, `lib/` ed estensioni incluse (ezoe, ezjscore).

**Files:**
- Modify: i file elencati da `vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 7.4-8.3 --sniffs=PHPCompatibility.ParameterValues.NewHTMLEntitiesFlagsDefault kernel lib extension`.

**Interfaces:**
- Consumes: decisione D1.
- Produces: output identico su 7.4 e 8.3.

- [ ] **Step 1: Applicare il flag deciso a tutte le chiamate elencate**

Se D1 = "comportamento 7.4": `ENT_COMPAT | ENT_HTML401` come secondo argomento. Se D1 = "comportamento 8.1": `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401`. Lo stesso vale per `htmlspecialchars_decode`, `html_entity_decode` e `get_html_translation_table`.

- [ ] **Step 2: Verifica**

Run: il comando `phpcs` sopra.
Expected: `0 errors`. Poi Task 3, Step 2, punti 2 e 3, con un titolo che contiene `l'acqua "pura"`: il titolo nella pagina e nel feed RSS deve apparire identico su 7.4 e 8.3.

- [ ] **Step 3: Commit**

```bash
git commit -am "Fix: explicit htmlspecialchars flags, same output on PHP 7.4 and 8"
```

## Task 5: Pubblicazione del branch

- [x] **Step 1: Push** — fatto il 2026-11-04: `php8` pubblicato su `Opencontent/ezpublish-legacy`, dopo aver riscritto in inglese i messaggi dei nostri commit. I commit di Netgen hanno mantenuto i loro hash.
- [ ] **Step 2: PR `php8` → `master`** con il link a questo piano e all'analisi. Il merge in `master` va fatto solo dopo i Task 1–3 e dopo che il CMS su 7.4 è stato verificato con il kernel `php8` (boat e saasopenpa).
- [ ] **Step 3: Tag** `2020.1000.17` (o una nuova serie per PHP 8, vedi D6) e aggiornamento di `composer.lock` nel CMS.

---

## Fuori da questo repo

Ognuna di queste aree ha bisogno di un piano suo, nel repo che la contiene.

**Estensioni** (`gitlab.com/opencity-labs/ezpublish/*`), dal lint con PHP 8.3 di tutte le 58 estensioni:

- [ ] Fatale: `openpa/lib/Yaml/Unescaper.php:70` — sintassi `$s{$i}`
- [ ] Fatale: `ezflow/classes/ezflowajaxcontent.php:382` — sintassi `$s{$i}`
- [ ] Fatale: `ocmaintenance/classes/csv/csvrowset.php:127` — sintassi `$s{$i}`
- [ ] Fatale: `openpa/bin/php/change_password.php:28` — ternario annidato senza parentesi
- [ ] `continue` dentro `switch` (oggi equivale a `break`, probabile bug): `ezfind/classes/ezfezpsolrquerybuilder.php:939,1165,1730`, `ezodf/classes/ezooimport.php:660,673,686,714`, `occustomfind/classes/interface/OCCustomSearchableRepositoryAbstract.php:380`
- [ ] Return type: `openpa` (`OpenPADFSFileHandlerDFSAmazonFilterIterator::accept/current`), `ComposerLockParser\PackagesCollection::offsetSet`
- [ ] Parametro opzionale prima di uno obbligatorio: `cjw_newsletter` (`cjwnewsletteruser.php:222,271`, `cjwnewslettercsvparser.php:33`), `ezfind/classes/ezfezpsolrquerybuilder.php:589`, `ezpostgresqlcluster/clustering/dfs/ezpostsgresqlbackend.php:1372`, `oceditorialstuff/classes/oceditorialstuffpost.php:40`, `openpa_bootstrapitalia/modules/manifest.json/manifest.php:46`
- [ ] Lint esteso a saasopenpa: le estensioni legacy (ezsurvey, mugoobjectrelations, ngopengraph…) non sono nel CMS locale

Gli `Unterminated comment` sui file `*.ini.append.php` sono falsi positivi: eZ li legge come testo e non li carica mai come PHP.

**Repo CMS** (`cms/`):

- [ ] CMS-1: `docker/php-nginx/provision/entrypoint.d/20-php.sh` — `chown $EZ_USER.$EZ_USER_GROUP` diventa `$EZ_USER:$EZ_USER_GROUP` (7 righe). BusyBox 1.37 rifiuta la forma con il punto e il container si ferma.
- [ ] CMS-2: immagine base PHP 8.3 (`registry.gitlab.com/opencity-labs/sito-istituzionale/php-nginx`) con le stesse estensioni della 7.4. Su `webdevops/php-nginx:8.3-alpine` manca solo `rdkafka`. Serve anche la patch PHP2Shell (CVE-2026-109618/109619) per 8.3, o la verifica che 8.3.36 non ne abbia bisogno.
- [ ] CMS-3: `composer.json` — `"php": ">=7.4 <8.0"` diventa `"^7.4 || ^8.2"`, poi aggiornare il kernel al tag del Task 5 e rigenerare `composer.lock`.
- [ ] CMS-4: dipendenze di `vendor` su PHP 8.3: `composer why-not php 8.3` e `composer outdated --direct`.

## Decisioni aperte

| # | Decisione | Opzioni | Note |
| --- | --- | --- | --- |
| D1 | Flag di default di `htmlspecialchars` | Tenere il comportamento 7.4 (`ENT_COMPAT`) / adottare quello 8.1 (`ENT_QUOTES`) | Blocca il Task 4. Il secondo escapa anche `'`, più sicuro, ma cambia l'output |
| D2 | Permesso obbligatorio su `ezjscnode` (Mugo #224) | Tenerlo fuori / reintrodurlo con la policy `ezjscore/call` per gli anonimi | Annullato in `f6349d0e6e`. Chiude chiamate AJAX anonime ai sottoalberi |
| D3 | Formato del log di `ezdebug` (Mugo #219: etichetta e messaggio sulla stessa riga) | Tenere / tornare al formato su due righe | Già nel branch. Va controllato se qualcuno analizza i log in automatico |
| D4 | PHPUnit | Restare a 4.8.36 / passare alla 10 come Netgen e aggiornare i test | 4.8 non gira su PHP 8 |
| D5 | Default di `data_text` in `ezcontentobject_attribute` | Nessuna modifica / migrazione Postgres equivalente alla `lovestack/4.sql` di Mugo | Il fix PHP 8 di ezmatrix è già arrivato con Netgen |
| D6 | Versione PHP di esercizio e numerazione dei tag | 8.2 / 8.3; continuare con `2020.1000.x` / nuova serie | Mugo dichiara <8.4 |

## Ambiente di prova PHP 8.3

`php8-testenv/setup.sh` crea un progetto Docker separato, `cmsphp8`, con queste caratteristiche:
- **Codice:** `html/` del CMS con sopra il kernel `KERNEL_REF` (default `php8`).
- **Immagine:** `webdevops/php-nginx:8.3-alpine` più `rdkafka` e gli script di avvio del CMS.
- **Dati:** copie dei volumi `cms_*` in `cmsphp8_*`, quindi l'installazione locale non viene toccata.
- **Accesso:** `127.0.0.1:18080`, senza Traefik né Varnish, con l'installer spento.

```bash
docs/superpowers/plans/php8-testenv/setup.sh        # crea e avvia
curl -H 'Host: opencity.localtest.me' http://127.0.0.1:18080/
docs/superpowers/plans/php8-testenv/setup.sh down   # spegne
docker volume rm cmsphp8_pgdata cmsphp8_dfs cmsphp8_miniodata cmsphp8_solrdata   # per ripartire da una copia nuova
```

Lo stack `cms` deve essere spento mentre si copiano i volumi. Gli errori `CREATE TABLE … already exists` nei log vengono dallo step dello schema base, che riparte su un database già installato, e non dipendono da PHP.

## Riferimenti

- Analisi della base: <https://claude.ai/artifact/7cmgiu2j6UoLcWqQprsXWw>
- [netgen/ezpublish-legacy@ibexa-support](https://github.com/netgen/ezpublish-legacy/tree/ibexa-support) — base dei fix PHP 8
- [mugoweb/ezpublish-legacy](https://github.com/mugoweb/ezpublish-legacy) — fonte dei cherry-pick
- [se7enxweb/exponential](https://github.com/se7enxweb/exponential) — catalogo di fix (es. `_metaData`, `session_module_name`)
- Fork scaricati in locale per i confronti: `refs/cmp/{netgen,mugo,se7enx,ezsys,occoop}`
