# Easy Crates

Advanced crates plugin for PocketMine-MP (API 5.x). Includes a GUI editor, weighted rewards, global placements, and key-based opening.

## Features
- Create and edit crates in-game
- Weighted rewards with per-item probability
- Global crate placement (all players can use the same block)
- Key system for opening crates
- View items by left-clicking the crate
- Right-click to open (requires key)
- Block protection (players + explosions)
- PMServerUI forms + InvMenu GUI editor

## Requirements
- PocketMine-MP 5.x
- Virions:
  - muqsit/InvMenu
  - DavyCraft648/PMServerUI

## Commands
- `/crate create <id> <format>`
- `/crate edit <id> item|probability`
- `/crate place <id>`
- `/crate remove`
- `/crate delete <id>`
- `/crate givekey <id> <amount> <player|all>`
- `/crate reload`

## Usage
1) Create a crate:
```
/crate create starter &aStarter
```

2) Add items in the GUI, close to save.

3) Set weights:
```
/crate edit starter probability
```

4) Place the crate:
```
/crate place starter
```
Then click a block to place it.

5) Give keys:
```
/crate givekey starter 1 PlayerName
```

## Notes
- Left-click shows item list
- Right-click opens the crate (requires a key)
- Hologram clears on chunk reload

## Poggit
Add `.poggit.yml`:
```
projects:
  Crates:
    path: ""
    libs:
      - src: muqsit/InvMenu/InvMenu
        version: ^4.6.3
      - src: DavyCraft648/PMServerUI/PMServerUI
        version: ^1.0.2
```

Before pushing to Poggit, remove embedded libs from `src/`.

## Author
Cykp
