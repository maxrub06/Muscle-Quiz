# Muscle-Quiz

Muscle Quest includes three quiz levels, a daily 10-question challenge, muscle-group practice, persistent achievements, and the Flappy Muscles and Muscario minigames. Daily challenges are selected consistently for each local calendar day.

Use the Settings button to control menu and minigame sounds or install the app. Installation requires HTTPS or localhost and a browser that supports app installation.

## Online leaderboard (MySQL 8.0 / Strato)

Quiz results, achievements, daily streaks, minigame records, and settings are otherwise still stored on the current device. Existing local leaderboard scores are not uploaded automatically. To enable the shared leaderboard:

1. Run the full [`database.sql`](./database.sql) in the MySQL 8.0 database provided by Strato. It creates both leaderboard and Muscario multiplayer tables.
2. Copy `api/config.example.php` to `api/config.php` and enter the database host, name, user, and password from Strato. Keep `config.php` private; it is excluded from Git and blocked from direct web access.
3. Upload the app, including the `api` folder, to the same PHP-enabled HTTPS website. The PHP PDO MySQL extension must be enabled.
4. Open `Leaderboard.html` on the website and save a test result. When the leaderboard API is unavailable, the page falls back to scores saved in that browser.

The public leaderboard accepts anonymous, self-reported quiz scores. The API validates names, levels, and score ranges, but without user accounts or server-side quiz sessions it cannot prevent someone from submitting a fabricated score.

## Muscario online multiplayer

Muscario uses `api/muscario.php` to share player positions and mass in one live arena. It polls the PHP/MySQL server once per second; active players can see and eat one another, while inactive player records are removed after a day. The arena requires the site to be uploaded to the PHP-enabled HTTPS hosting above and the full database schema to be installed. Open the hosted game in two different browser profiles or devices to verify multiplayer. It does not connect players when opened directly as a local file.
