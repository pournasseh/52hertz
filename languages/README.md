# Languages

One file per language, named by its code: `xx.json`, or `xx-yy.json` with a
region. Put one here and 52Hertz offers it in **Settings → General** the next
time a page loads. Nothing else has to be edited.

## Making one

Copy `en.json`. It lists everything there is to say, each line mapped to
itself, so translating is replacing the right-hand side:

```
"Stations": "Stations"      ->   "Stations": "<your wording>"
```

Leave a line alone and it shows the wording it has in the code. A
half-finished translation is usable, never broken, so there is no reason to
wait until it is complete.

Fill in the `language` block at the top with your own language's name, its
direction (`ltr` or `rtl`), the locale to format dates and numbers with, and
its ten digits if it writes its own. `../README.md` has the full table.

`panel` is what the person running the radio reads. `player` is what their
listeners see — about thirty lines, and the ones worth doing first, since
every listener sees them.

## Checking it

From the panel's directory:

```
php tools/extract-strings.php
```

It reports, for every language here, what is still missing and what is left
over from wording the panel no longer uses.

## Two rules

**JSON only.** This folder serves nothing else over the web, because a `.php`
file arriving here from a stranger would be a program, not a translation.
That is also why translations are JSON and not PHP.

**Use your own code.** An upgrade replaces every file its own release ships
in this folder. A file under any other name is left alone.
