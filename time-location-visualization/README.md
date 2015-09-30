Spatio-temporal visualization of Freebase and Wikidata
======================================================


This small C++ program allows to display Freebase or Wikidata in a PNG image with as X axis the longitude of the topics/items and as Y axis the earliest date about this item.

## Installation

It requires a C++11 compiler, CMake and [png++](http://www.nongnu.org/pngpp/) (provided by the package `libpng++-dev` on Debian-based distributions).

To compile it, do the usual:

```
mkdir build
cd build
cmake ..
make
```

## Usage

### Freebase

1. Create the Freebase dump restricted to facts using the `convert/explode-dump.py` script.
2. Run `./freebase my-facts-dump.nt`. It creates two images `freebase.png` and `freebase-grid.png` in the current directory.

### Wikidata

1. create a simple TSV file from Wikidata JSON dump using the `wikidata/wikidata-tsv-simple.php` script.
2. Run `./wikidata my-tsv-file.tsv`. It creates two images `wikidata.png` and `wikidata-grid.png` in the current directory.

