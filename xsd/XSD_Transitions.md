# Full XSD Transition Report

This document aggregates all schema differences for each consecutive version transition.

## Global Summary

| Transitions |
|---|
| 3.0.0 → 3.0.1 |
| 3.0.1 → 3.0.2 |
| 3.0.2 → 3.1.0 |
| 3.1.0 → 3.1.1 |
| 3.1.1 → 3.2.0 |
| 3.2.0 → 3.3.0 |
| 3.3.0 → 3.4.0 |
| 3.4.0 → 3.5.0 |

## 3.0.0 → 3.0.1

**TargetNamespaces**:
- 3.0.0: http://pkp.sfu.ca
- 3.0.1: http://pkp.sfu.ca

### Include Graph Changes
_No include/import changes detected._

### Elements
**Added elements:**
- agencies
- agency
- cover
- disciplin
- disciplines
- issue_covers
- issue_identification
- keyword
- keywords
- number
- pages
- subjects
- volume
- year
**Removed elements:**
- issue_cover

### Types
**Removed types:**
- issue_cover

### Enumeration Changes
_No enumeration changes._

## 3.0.1 → 3.0.2

**TargetNamespaces**:
- 3.0.1: http://pkp.sfu.ca
- 3.0.2: http://pkp.sfu.ca

### Include Graph Changes
_No include/import changes detected._

### Elements
**Removed elements:**
- comments_to_editor

### Types
_No type changes._

### Enumeration Changes
_No enumeration changes._

## 3.0.2 → 3.1.0

**TargetNamespaces**:
- 3.0.2: http://pkp.sfu.ca
- 3.1.0: http://pkp.sfu.ca

### Include Graph Changes
_No include/import changes detected._

### Elements
**Added elements:**
- copyrightHolder
- copyrightYear
- licenseUrl
- orcid

### Types
_No type changes._

### Enumeration Changes
_No enumeration changes._

## 3.1.0 → 3.1.1

**TargetNamespaces**:
- 3.1.0: http://pkp.sfu.ca
- 3.1.1: http://pkp.sfu.ca

### Include Graph Changes
_No include/import changes detected._

### Elements
**Added elements:**
- covers
- discipline
- familyname
- givenname

**Removed elements:**
- disciplin
- firstname
- issue_covers
- lastname
- middlename

### Types
_No type changes._

### Enumeration Changes
_No enumeration changes._

## 3.1.1 → 3.2.0

**TargetNamespaces**:
- 3.1.1: http://pkp.sfu.ca
- 3.2.0: http://pkp.sfu.ca

### Include Graph Changes
_No include/import changes detected._

### Elements
**Added elements:**
- citation
- citations
- file
- issueId
- languages
- masthead
- permit_metadata_edit
- pkppublication
- publication
- ror
- rorAffiliation

**Removed elements:**
- revision

**Changed elements:**
- **affiliation**
  - type: `pkp:localizedNode` → `(anonymous complexType)`

### Types
**Added types:**
- pkppublication

### Enumeration Changes
_No enumeration changes._

## 3.2.0 → 3.3.0

**TargetNamespaces**:
- 3.2.0: http://pkp.sfu.ca
- 3.3.0: http://pkp.sfu.ca

### Include Graph Changes
_No include/import changes detected._

### Elements
**Removed elements:**
- artwork_file
- caption
- copyright_owner
- copyright_owner_contact
- credit
- date_created
- masthead
- permission_terms
- ror
- rorAffiliation
- supplementary_file
**Changed elements:**
- **affiliation**
  - type: `(anonymous complexType)` → `pkp:localizedNode`
- **citation**
  - minOccurs: `0` → `None`
  - maxOccurs: `unbounded` → `None`
- **language**
  - minOccurs: `0` → `1`
  - maxOccurs: `1` → `unbounded`
- **subject**
  - type: `pkp:localizedNode` → `string`
  - minOccurs: `0` → `1`

### Types
_No type changes._

### Enumeration Changes
_No enumeration changes._

## 3.3.0 → 3.4.0

**TargetNamespaces**:
- 3.3.0: http://pkp.sfu.ca
- 3.4.0: http://pkp.sfu.ca

### Include Graph Changes
_No include/import changes detected._

### Elements
_No element changes._

### Types
_No type changes._

### Enumeration Changes
_No enumeration changes._

## 3.4.0 → 3.5.0

**TargetNamespaces**:
- 3.4.0: http://pkp.sfu.ca
- 3.5.0: http://pkp.sfu.ca

### Include Graph Changes
_No include/import changes detected._

### Elements
**Added elements:**
- masthead
- ror
- rorAffiliation
**Changed elements:**
- **affiliation**
  - type: `pkp:localizedNode` → `(anonymous complexType)`

### Types
_No type changes._

### Enumeration Changes
_No enumeration changes._
