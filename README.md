# OJS XML Converter

**Convert OJS “native” XML exports between versions**
This tool takes an input XML exported from one OJS version and transforms it into the “native” XML expected by another version, using **pure PHP transforms**.

* Version-to-version **hops** (e.g., `2.4.8 → 3.0.0 → 3.0.1 → …`)
* **Schema validation** at each step (XSDs for 3.x, DTD for 2.4.8)
* **Central stashing/unstashing** of fields that disappear/reappear across versions

---

## Table of contents

* [Quick start](#quick-start)
* [Requirements](#requirements)
* [Usage](#usage)
* [Validation (XSD/DTD)](#validation-xsddtd)
* [Supported hops](#supported-hops)
* [Add a new hop](#add-a-new-hop)
* [Stashing & restoring fields](#stashing--restoring-fields)
* [XSD/DTD folder layout](#xsddtd-folder-layout)

---

## Quick start

```bash
# Convert a 2.4.8 export to 3.3.0
php bin/xml-convert.php \
  --from=2.4.8 \
  --to=3.3.0 \
  --in=path/to/native-248.xml \
  --out=path/to/native-330.xml \
  --debug \
  --validate-strict
```

* `--debug` prints a step-by-step log of what changed.
* `--validate-strict` enforces schema checks per hop (recommended).

> Tip: the converter will chain the necessary hops automatically (`2.4.8 → 3.0.0 → 3.0.1 → … → 3.3.0`).

---

## Requirements

* PHP 8.1+ (DOM, libxml extensions enabled)
* CLI access
* The target OJS **XSDs** (and 2.4.8 **DTD**) placed under the `xsd/` folder (see below)

## Usage

```bash
php bin/xml-convert.php --from=<version> --to=<version> --in=<file> --out=<file> [--debug] [--validate-strict]
```

---

## Validation (XSD/DTD)

Validation happens in a **central** `SchemaValidator`:

* For **3.x** versions, it locates:

  * `xsd/<version>/plugins/importexport/native/native.xsd`
  * `xsd/<version>/lib/pkp/plugins/importexport/native/pkp-native.xsd` (included)
* For **2.4.8**, if no XSDs are present for that version, it will look for:

  * `xsd/2.4.8/native.dtd` and validate with DTD.

> You can toggle strict validation with `--validate-strict`. When on, input and output are checked around each hop; when off, only the final output is validated (if you p.

---

## Supported hops

Out of the box the pipeline knows how to chain these steps:

* `2.4.8 → 3.0.0`
* `3.0.0 → 3.0.1`
* `3.0.1 → 3.0.2`
* `3.0.2 → 3.1.0`
* `3.1.1 → 3.2.0`
* `3.2.0 → 3.3.0`
* `3.3.0 → 3.4.0`
* `3.4.0 → 3.5.0`

> There’s a no change hop - **NoChangesHop** - that can be wired between versions where only validation happen.

---

## Add a new hop

1. Create `src/PhpTransform/Hop_<from>_to_<to>.php` with:

   * `transform(DOMDocument $doc, bool $debug = false): DOMDocument`
2. Register it in `Pipeline.php` in the hop map.
3. Drop the target **XSDs** under `xsd/<to>/` (see below).
4. Run with `--validate-strict` and fix any sequence/order errors.

---

## Stashing & restoring fields

Some elements disappear in one version and reappear later. The converter uses a **central stash** to preserve data:

* **`<pages>`**: present in 2.4.8, disallowed in 3.0.0, returns in 3.0.1.

  * `2.4.8 → 3.0.0`: stash pages per article
  * `3.0.0 → 3.0.1`: restore `<pages>` in the correct article and correct position
* **`<permissions>`** block (license/copyright) can be **stashed** and later **re-materialized** as `licenseUrl`, `copyrightHolder`, `copyrightYear` under the right node.

The stash attaches **non-intrusive processing instructions** to the specific article so data is returned to the *same* logical record even if the DOM node identity changes between hops.

---

## XSD/DTD folder layout

```
xsd/
  2.4.8/
    native.dtd
  3.0.0/
    plugins/importexport/native/native.xsd
    lib/pkp/plugins/importexport/native/pkp-native.xsd
    lib/pkp/xml/importexport.xsd
  3.0.1/
    …
  3.0.2/
    …
  3.1.0/
    …
  3.1.1/
    …
  3.2.0/
    …
  3.3.0/
    …
  3.4.0/
    …
  3.5.0/
    …
```

> Source these files directly from the corresponding OJS release so includes and imports resolve cleanly.

---
