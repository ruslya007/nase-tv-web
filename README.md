# Naše TV – web (statická verze pro hosting WEDOS)

- `index.html` – hlavní stránka pro diváky
- `studio/index.php` – programové studio (administrace), přístup chráněný heslem
- `assets/svgplayer.js` – přehrávač virtuálních moderátorů

Stránky jsou sestavené a minifikované z vývojové verze. Data studia se zatím ukládají jen v prohlížeči.

## Vysílací API a domácí výrobna
- `api/vysilani.php` – studio zveřejní schválené zprávy (`📡 Zveřejnit na web` na Pultu), domácí výrobna (Windows, Piper + Argos) k nim doplní hlas MP3 s časy slov a strojové překlady, divácká stránka je přehraje.
- Data: `data/vysilani/` (mimo git). Výrobna se přihlašuje hlavičkou `X-NTV-Key` (na serveru je jen hash).

## Silniční oko (pilot)
- `oko/` – v0.1: detekce objektů a rychlost mezi dvěma branami
- `oko/v2/` – v0.2: 3D odhad rychlosti, galerie vozidel a učení z potvrzených modelů
