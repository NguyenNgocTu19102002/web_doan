Setup for automated daily fetch of Lời Chúa

1) Prerequisites (Windows, XAMPP already installed):
 - Install Node.js (LTS) from https://nodejs.org/ and ensure `node` and `npm` are in PATH.

2) Install dependencies (open PowerShell in project folder `c:\xampp\htdocs\tntt_lap_tri`):

```powershell
cd c:\xampp\htdocs\tntt_lap_tri\scripts
npm init -y
npm install puppeteer mysql2
```

Note: puppeteer will download a Chromium binary. If you prefer to use the system Chrome, you can set `executablePath` in the script.

3) Configure database
 - Ensure table `loichua_daily` exists (run in phpMyAdmin or mysql CLI):

```sql
CREATE TABLE IF NOT EXISTS loichua_daily (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ngay DATE NOT NULL UNIQUE,
  title VARCHAR(255),
  gospel LONGTEXT,
  reflection LONGTEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

4) Test script manually

```powershell
node fetch_loichua.js
```

Check database to see if today's row is inserted or updated.

5) Schedule daily run at 00:00 using Windows Task Scheduler
 - Open Task Scheduler -> Create Basic Task -> name: "Fetch Loi Chua"
 - Trigger: Daily, recur every 1 day, start: set to next midnight
 - Action: Start a program
   Program/script: C:\Program Files\nodejs\node.exe
   Add arguments: "C:\xampp\htdocs\tntt_lap_tri\scripts\fetch_loichua.js"
   Start in: C:\xampp\htdocs\tntt_lap_tri\scripts
 - Finish. Optionally set "Run whether user is logged on or not" and set highest privileges.

6) Logs
 - The script prints to console. To capture output, configure Task Scheduler action to run a .cmd wrapper file that redirects output to a log file.

If you want, tôi có thể:
 - Điều chỉnh script để dùng Chrome cài sẵn (tiết kiệm dung lượng)
 - Thêm file .cmd wrapper cho Task Scheduler
 - Thêm email thông báo khi thất bại
