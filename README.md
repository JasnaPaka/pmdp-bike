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
