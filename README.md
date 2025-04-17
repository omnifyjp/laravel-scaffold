# Laravel Scaffold

A powerful Laravel package that automates the generation of Models, Controllers, Migrations, and other essential components from database schemas.

## Installation

```bash
composer require omnifyjp/laravel-scaffold
```


# YAML Configuration Attributes Guide

General Structure of YAML Files

YAML configuration files are used to define objects (models) and the relationships between them in the system. Each file represents an object or model in the system.

## High-Level Attributes

### `kind`

* **Role**: Defines the type of configuration.
* **Common value**: `object` (data object).

### `displayName`

* **Role**: The name shown in the user interface.
* **Usage**: Displayed on forms, tables, and lists.

### `groupName`

* **Role**: Groups related objects together in the system.
* **Common values**: `System`, `Line`, or the name of a module in the system.

### `titleIndex`

* **Role**: Specifies which field is used as the title when displaying a list.
* **Example**: If `titleIndex: name`, when displaying a list, the value of the `name` field will be used as the title.

### `options`

* **Role**: Global configuration for the object.
* **Main options**:
  * `timestamps`: When `true`, automatically creates and manages `created_at` and `updated_at` fields.
  * `softDelete`: When `true`, records are not physically deleted but only marked as deleted.
  * `private`: Controls the privacy of the object.
  * `sortable`: Allows sorting of records.
  * `authenticatable`: Indicates if the object can be used for authentication.
  * `unique`: Defines unique constraints (single or composite fields).
  * `indexes`: List of fields to be indexed to optimize queries.
  * `polymorphic`: Allows polymorphic relationships (one object can be linked to many different types of objects).
  * `nestedSet`: Used for hierarchical tree structures.

### `properties`

* **Role**: Defines all data fields of the object.
* **Structure**: Each child property is a data field with its own configurations.

## Data Field Attributes

### `type`

* **Role**: Defines the data type of the field.
* **Basic types**:
  * `String`: Regular text string.
  * `Int`: Integer.
  * `BigInt`: Large integer.
  * `Boolean`: True/false value.
  * `Date`: Date (without time).
  * `Timestamp`: Date and detailed time.
  * `Time`: Time only.
  * `Text`: Long text.
  * `Json`: JSON-formatted data.
  * `Color`: Color value.
* **Special types**:
  * `Email`: Email address.
  * `JapanPhone`: Phone number in Japanese format.
  * `JapanAddress`: Japanese address.
  * `JapanPersonName`: Japanese person's name.
  * `Password`: Password (automatically encrypted).
  * `File`: Single file.
  * `MultiFile`: Multiple files.
  * `Enum`: Fixed list of values.
  * `Select`: Reference to a global selection list.
  * `Association`: Relationship between objects.
  * `Polymorphic`: Polymorphic relationship.
  * `Lookup`: Search and reference.

### `displayName`

* **Role**: The display name of the field in the user interface.
* **Usage**: Shown on forms, tables, lists.

### `nullable`

* **Role**: Allows the field to have a null value or not.
* **Values**: `true` or `false`.
* **Default**: `false` if not specified.

### `default`

* **Role**: Sets the default value when creating a new record.
* **Value type**: Depends on the data type of the field.

### `index`

* **Role**: Creates an index to optimize search/query on this field.
* **Values**: `true` or `false`.
* **Effect**: Improves performance when searching by this field.

### `unique`

* **Role**: Ensures the value of the field is unique in the data table.
* **Values**: `true` or `false`.

### `private`

* **Role**: Controls the privacy of the data field.
* **Values**: `true` or `false`.
* **Effect**: When `true`, the value is not publicly displayed and is protected.

### `editable`

* **Role**: Determines whether the field can be edited or not.
* **Values**: `true` or `false`.
* **Default**: `true` if not specified.

### `rules`

* **Role**: Defines data validation rules for the field.
* **Common rules**:
  * `required`: Required input.
  * `minLength`: Minimum length.
  * `maxLength`: Maximum length.

### `accept`

* **Role**: Applied to fields of type `File` or `MultiFile`, specifies the accepted file types.
* **Value**: List of file extensions separated by commas (e.g., `.jpg,.jpeg,.png`).

## Attributes for Special Data Types

### Attributes for `Enum` type

#### `enum`

* **Role**: Defines the list of fixed values for `Enum` type fields.
* **Structure**: Array of objects with properties:
  * `value`: Value stored in the database.
  * `label`: Label displayed in the user interface.

### Attributes for `Select` type

#### `select`

* **Role**: References a globally defined selection list.
* **Syntax**: `Global::DefinitionName` (e.g., `Global::Gender`).

## Relationship Attributes Between Objects

### Attributes for `Association` type

#### `relation`

* **Role**: Defines the type of relationship between objects.
* **Relationship types**:
  * `ManyToOne`: Many-to-one (e.g., many patients belong to one pharmacy).
  * `ManyToMany`: Many-to-many (e.g., a user has many roles, a role applies to many users).
  * `OneToMany`: One-to-many (e.g., one post has many comments).
  * `OneToOne`: One-to-one (e.g., one user has one profile).

#### `target`

* **Role**: Specifies the target object of the relationship.
* **Value**: Name of the target object (e.g., `Pharmacy`, `User`).

#### `inversedBy`

* **Role**: Specifies the name of the property in the target object that points back to the current object.
* **Meaning**: Establishes a bidirectional relationship, allowing access from the target object back to the current object.
* **Example**: If `Patient` has a relationship with `Pharmacy` through the `pharmacy` field and `inversedBy: patients`, then in `Pharmacy` there will be a `patients` field pointing back to all related `Patient` records.

#### `mappedBy`

* **Role**: In a bidirectional relationship, specifies which object is the owner of the relationship.
* **Usage**: Specifies the non-owning side in a bidirectional relationship.
* **Example**: In a relationship between `Pharmacy` and `Notice`, if `Pharmacy` declares `mappedBy: notices`, then the `Notice` object is the owner of the relationship.

### Attributes for `Polymorphic` type

#### `relation`

* **Role**: Same as in `Association`, defines the type of relationship.

#### `target`

* **Role**: Specifies the target object of the polymorphic relationship.
* **Characteristic**: The target object must have the option `polymorphic: true`.

#### `inversedBy`

* **Role**: Similar to `Association`, but applied to polymorphic relationships.

## Self-created Example: Product Management

```yaml

kind: object

displayName: Product

groupName: Inventory

titleIndex: product_name

options:
  timestamps: true
  softDelete: true
  sortable: true
  indexes:
    - product_code
    - category_id_relation_field
    - status

properties:
  product_code:
    type: String
    displayName: Product Code
    unique: true
    index: true
    rules:
      required: true
      maxLength: 50
  
  product_name:
    type: String
    displayName: Product Name
    rules:
      required: true
      maxLength: 255
  
  description:
    type: Text
    displayName: Description
    nullable: true
  
  price:
    type: Decimal
    displayName: Selling Price
    rules:
      required: true
  
  cost:
    type: Decimal
    displayName: Cost Price
    nullable: true
    private: true
  
  status:
    type: Enum
    displayName: Status
    enum:
      - value: active
        label: Active
      - value: out_of_stock
        label: Out of Stock
      - value: discontinued
        label: Discontinued
    default: active
    index: true
  
  stock_quantity:
    type: Int
    displayName: Stock Quantity
    default: 0
  
  images:
    type: MultiFile
    accept: .jpg,.jpeg,.png
    displayName: Product Images
    nullable: true
  
  specifications:
    type: Json
    displayName: Technical Specifications
    nullable: true
  
  category:
    type: Association
    relation: ManyToOne
    target: ProductCategory
    inversedBy: products
    displayName: Category
    nullable: true
  
  tags:
    type: Association
    relation: ManyToMany
    target: Tag
    inversedBy: products
    displayName: Tags
  
  supplier:
    type: Association
    relation: ManyToOne
    target: Supplier
    inversedBy: products
    displayName: Supplier
    nullable: true
  
  promotion:
    type: Association
    relation: ManyToMany
    target: Promotion
    inversedBy: products
    displayName: Promotions
  
  reviews:
    type: Association
    relation: OneToMany
    target: ProductReview
    inversedBy: product
    displayName: Reviews
  
  featured:
    type: Boolean
    displayName: Featured Product
    default: false
  
  launch_date:
    type: Date
    displayName: Launch Date
    nullable: true
  
  last_restock_at:
    type: Timestamp
    displayName: Last Restock Time
    nullable: true
    editable: false
```

Above is an example of a YAML configuration for a Product object in an inventory management system, demonstrating the use of the attributes explained. The object has various relationships with other objects such as Category, Supplier, Promotion, and Review.