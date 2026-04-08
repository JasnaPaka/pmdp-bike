# Statistika sdílených elektrokol PMDP

Projekt zobrazuje statistické informace o [sdílených elektrokolech](https://www.pmdp.cz/bike/) 
provozovaných Plzeňským městským dopravním podnikem (PMDP). V pravidelných intervalech
se zaznamenávají údaje z veřejně dostupného rozhraní [GBFS](https://gbfs.org/).

Hlavní motivací vzniku bylo vyzkoušet možnosti vibecodingu pomocí nástroje
Claude Code. Použit byl model Sonnet 4.6. Zdrojový kód nebyl ručně
upravován.

## Instalace

Aplikace vyžaduje PHP 8.2 či vyšší s podporu pro práci s SQLite. 
Pro monitoring je potřeba spouštět v pravidelných intervalech 
cronem skript `collector.php`. 

## Statistika výpůjček

Modul výpůjček sleduje, kdy konkrétní kolo zmizí ze stanice (= výpůjčka), a ukládá
data do MySQL databáze. Výpůjčky jsou zobrazeny na stránce `vypujcky.php`.

### Konfigurace

1. Zkopírujte výchozí konfigurační soubor a doplňte přístupové údaje k MySQL databázi:

   ```bash
   cp config_default.php config.php
   ```

   Otevřete `config.php` a vyplňte hodnoty `DB_HOST`, `DB_NAME`, `DB_USER` a `DB_PASS`.

2. Vytvořte databázové tabulky:

   ```bash
   mysql -u <user> -p <databáze> < create.sql
   ```

3. Nastavte cron pro pravidelný sběr dat (doporučeno každých 5 minut):

   ```
   */5 * * * * php /cesta/k/collector_vypujcky.php >> /var/log/pmdp_bike_vypujcky.log 2>&1
   ```
