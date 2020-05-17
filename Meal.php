<?php

namespace app\models;

use app\helpers\SecurityHelper;
use Yii;
use yii\base\Security;
use yii\helpers\BaseFileHelper;
use yii\helpers\Url;

/**
 * This is the model class for table "meal".
 *
 * @property integer $meal_id
 * @property string $title
 * @property string $recipe_caption
 * @property string $image
 * @property string $thumbnail
 * @property string $description
 * @property string $recipe
 * @property string $type
 * @property string $recipe_url
 */
class Meal extends \yii\db\ActiveRecord
{

    public $file;

    const maxFileSize = 2050; // kb
    const dailyType = "daily";
    const restaurantType = "restaurant";

    private static $types = [
        self::dailyType => "Daily",
        self::restaurantType => "Restaurant"
    ];

    private static $default_type = self::dailyType;

    private static $type_properties = [

            'is_visible_default' => [
                self::dailyType => true,
                self::restaurantType => false
            ],
            'is_visible_directions' => [
                self::dailyType => true,
                self::restaurantType => false
            ],
            'is_visible_description' => [
                self::dailyType => true,
                self::restaurantType => true
            ]
    ];

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'meal';
    }

    /**
     * @param $prop
     * @return array
     */
    public static function getTypeProperties($prop)
    {
        return self::$type_properties[$prop];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['title'], 'required'],
            [['title'], 'unique'],
            [['description', 'recipe', 'recipe_url'], 'string'],
            [['title'], 'string', 'max' => 100],
            [['recipe_caption'], 'string', 'max' => 100],
            [['type'], 'in', 'range' => self::getTypesAsScalar()],
            [['image'], 'string', 'max' => 255],
            [['thumbnail'], 'string', 'max' => 255]
        ];
    }


    /**
     * @return array
     */
    public static function getTypes()
    {
        return self::$types;
    }

    public static function getTypesAsScalar() {
        return array_keys(self::$types);
    }

    /**
     * @param $phase_id
     * @param $day
     * @param $category_id
     * @return array
     */
    public static function getAvailableTypes($phase_id, $day, $category_id) {
        $meal_tbl = Meal::tableName();
        $menu_tbl = PhaseMenu::tableName();
        $data = self::find()
            ->leftJoin($menu_tbl, 'phase_menu.meal_id = meal.meal_id')
            ->select($meal_tbl.'.type')
            ->where([$menu_tbl.'.phase_id' => $phase_id, $menu_tbl.'.day_of_phase' => $day, $menu_tbl.'.category_id' => $category_id])
            ->groupBy($meal_tbl.'.type')
            ->asArray()
            ->all();
        return array_column($data, 'type');
    }

    /**
     * @return string
     */
    public static function getDefaultType() {
        return self::$default_type;
    }

    public function getIngredientsGrid() {
        $output = "";
        foreach ($this->mealIngredients as $key => $m_ing) {
            if (isset($m_ing->ingredient))
                $output .= "<div class='col-md-4'><p>" . $m_ing->portion . "&nbsp;" . $m_ing->ingredient->title . "</p></div>";
        }
        return $output;
    }

    public function getAlternativeIngredientsGrid() {
        $ingredients = [];
        foreach ($this->alternativeIngredients as $ing) {
            if (!empty($ing->alternative_ingredients))
                if (isset($ing->defaultIngredient))
                    $ingredients[$ing->defaultIngredient->ingredient_id][] = ['default' => $ing->defaultIngredient->title, 'alternative' => $ing->alternative_ingredients];
        }
        $output = "<ul class='b-footer__ingredients-list'>";
            foreach ($ingredients as $key => $ing) {
            $output .= "<li>";
            $output .= "<span class='b-footer-nav__menu-heading'><strong>" . $ing[0]['default'] . "</strong></span>";
            $output .= "<ul class='b-footer-nav__list'>";
            foreach ($ing as $current) {
                $alternative_ing = array_filter(explode(';', $current['alternative']));
                foreach ($alternative_ing as $record) {
                    $output .= "<li>" . trim($record) . "</li>";
                }
            }
            $output.='</ul>';
            $output .= "</li>";
        }
        $output.= "</ul>";
        return $output;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'meal_id' => 'Meal',
            'title' => 'Title',
            'description' => 'Description',
            'recipe' => 'Directions',
            'recipe_url' => 'Recipe url',
        ];
    }
    public static function getMeals($type = "daily") {
        return self::find()->where(["type" => $type])->orderBy('title')->asArray()->all();
    }

    public static function getMealsByIds($meal_ids, $type = "daily") {
        return self::find()->where(["meal_id" => $meal_ids, "type" => $type])->orderBy('title')->asArray()->all();;
    }

    public function getRecipe($prefix = "") {
        return Url::to([$prefix.'show-recipe', 'meal_id' => $this->meal_id], true);
    }

    public function getIngredients() {
        return $this->hasMany(Ingredient::className(), ['ingredient_id' => 'ingredient_id'])
            ->viaTable('meal_ingredient', ['meal_id' => 'meal_id']);
    }

    public function getMealIngredients() {
        return $this->hasMany(MealIngredient::className(), ['meal_id' => 'meal_id']);
    }
    public function getAlternativeIngredients() {
        return $this->hasMany(AlternativeMealIngredients::className(), ['meal_id' => 'meal_id']);
    }
}
