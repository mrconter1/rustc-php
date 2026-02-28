# rustc-php

> Under Development

## Installation

In order to build Rust code you of course first need to install PHP. You can do this easily on Windows 11 by running this command:

```
winget install PHP.PHP.8.4
```

This compiler outputs valid machine code for Linux, so the most practical approach if you're on Windows is to use WSL. Start by installing Ubuntu (if you haven't already):

```
wsl --install
```

After the install completes, reboot your machine and then open Ubuntu from the Start menu to finish the initial setup. Once that's done, you can run compiled binaries directly from WSL:

```
wsl ./your_program
```
