<?hh // strict
/**
 * Copyright (c) 2016, Facebook, Inc.
 * All rights reserved.
 *
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree. An additional
 * grant of patent rights can be found in the PATENTS file in the same
 * directory.
 *
 */

namespace Facebook\HHAST\Migrations;

use namespace Facebook\HHAST;
use namespace HH\Lib\{C, Str, Vec};
use type Facebook\TypeAssert\TypeAssert;

final class OptionalShapeFieldsMigration extends BaseMigration {
  private static function makeNullableFieldsOptional(
    HHAST\ShapeTypeSpecifier $shape,
  ): HHAST\ShapeTypeSpecifier {
    $fields = $shape->fields();
    if (!$fields) {
      return $shape;
    }
    return $shape->with_fields(
      Vec\map(
        $fields->children(),
        $node ==> {
          if (!$node instanceof HHAST\ListItem) {
            return $node;
          }

          $field = $node->item();

          if (!$field instanceof HHAST\FieldSpecifier) {
            return $node;
          }

          if (!$field->question() === null) {
            return $node;
          }

          $type = $field->type();
          if (!$type instanceof HHAST\NullableTypeSpecifier) {
            return $node;
          }

          if (!$field->raw_question()->is_missing()) {
            return $node;
          }

          $name = $field->name()->rightmost_tokenx();
          return $field->with_question(
            new HHAST\QuestionToken(
              $name->leading(),
              HHAST\Missing(),
            ),
          )->with_name(
            $name->with_leading(HHAST\Missing()),
          ) |> $node->with_item($$);
        },
      )
      |> new HHAST\EditableList($$),
    );
  }

  // Required for adding ellipsis
  private static function addTrailingCommaToFields(
    HHAST\ShapeTypeSpecifier $shape,
  ): HHAST\ShapeTypeSpecifier {
    $fields = $shape->fields();
    if ($fields === null) {
      return $shape;
    }

    $last_field = C\lastx($fields->children())
      |> TypeAssert::isInstanceOf(HHAST\ListItem::class, $$);

    if (!$last_field->raw_separator()->is_missing()) {
      return $shape;
    }

    return $shape->rewrite_children(
      ($node, $_) ==> {
        if ($node !== $last_field) {
          return $node;
        }
        return $last_field->with_separator(
          new HHAST\CommaToken(
            HHAST\Missing(),
            $last_field->rightmost_tokenx()->trailing(),
          ),
        )
          ->with_item(
            $last_field->item()->rewrite_children(
              ($inner, $_) ==> {
                if ($inner !== $last_field->rightmost_tokenx()) {
                  return $inner;
                }
                return $last_field
                  ->rightmost_tokenx()
                  ->with_trailing(HHAST\Missing());
              },
            ),
          );
      }
    );
  }

  private static function allowImplicitSubtypes(
    HHAST\ShapeTypeSpecifier $shape,
  ): HHAST\ShapeTypeSpecifier {
    $fields = $shape->of_class(HHAST\FieldSpecifier::class);
    $first_field = C\first($fields);
    if ($first_field === null) {
      return $shape->with_ellipsis(
        new HHAST\DotDotDotToken(HHAST\Missing(), HHAST\Missing()),
      );
    }

    return $shape->with_ellipsis(
      new HHAST\DotDotDotToken(
        Str\contains($shape->full_text(), "\n")
          ? $first_field->leftmost_tokenx()->leading()
          : new HHAST\WhiteSpace(' '),
        C\lastx($shape->fieldsx()->children())
          ->rightmost_tokenx()
          ->trailing(),
      ),
    );

    return $shape;
  }

  final public function getSteps(
  ): Traversable<IMigrationStep> {
    return vec[
      new TypedMigrationStep(
        'make nullable fields optional',
        HHAST\ShapeTypeSpecifier::class,
        HHAST\ShapeTypeSpecifier::class,
        $node ==> self::makeNullableFieldsOptional($node),
      ),
      new TypedMigrationStep(
        'add trailing commas to fields',
        HHAST\ShapeTypeSpecifier::class,
        HHAST\ShapeTypeSpecifier::class,
        $node ==> self::addTrailingCommaToFields($node),
      ),
      new TypedMigrationStep(
        'allow implicit subtypes',
        HHAST\ShapeTypeSpecifier::class,
        HHAST\ShapeTypeSpecifier::class,
        $node ==> self::allowImplicitSubtypes($node),
      ),
    ];
  }
}
