# fb_message_monitor
monitor your facebook account for unread messages

# getting started

prerequisites: you need php-cli >= 7.0.0 with the extensions php-curl php-xml php-json, 
if you're on MacOS then Apple already packed that into their OS (sounds bloaty),
if you're on Windows then you can get php-cli [here](https://www.cygwin.com/), 

first make a new text file, call it creds.txt (or whatever you want), put your facebook email in line 1, and your facebook password on line 2, don't make a line 3.

then download `fb_message_monitor_standalone.php` from here https://github.com/divinity76/fb_message_monitor/releases/tag/1.0.0 , 
and run in a terminal `php fb_message_monitor_standalone.php -c=path/to/creds.txt`

if everything's going smoothly, you should see some version of this:

```sh
logging in..done.
now checking every 60 seconds (roughly)
unread messages: int(1)
beeping, press the any key to abort.
logging in..done.
now checking every 60 seconds (roughly)
................
```
(with each dot representing each check, or 60 passed seconds-ish.)

when you do get a message, your pc will start beeping until you press a key in the terminal (which makes the script stop beeping and start monitoring again) - to turn off the script, just press ctrl+C
