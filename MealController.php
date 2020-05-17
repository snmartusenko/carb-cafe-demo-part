<?php

namespace app\controllers;

use app\models\AlternativeMealIngredients;
use app\models\FileUploader;
use app\models\Ingredient;
use app\models\MealIngredient;
use app\models\MealSearch;
use app\models\PhaseMenu;
use app\models\UserMenu;
use Yii;
use app\models\Meal;
use yii\db\Exception;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\web\UploadedFile;

/**
 * MealController implements the CRUD actions for Meal model.
 */
class MealController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['index','create','update','delete','view'],
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['index','create','update','delete','view'],
                        'roles' => ['admin'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Lists all Meal models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new MealSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }


    /**
     * Displays a single Meal model.
     * @param integer $id
     * @return mixed
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel($id),
        ]);
    }

    /**
     * Creates a new Meal model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $model = new Meal();
        $ingredients = Ingredient::find()->groupBy('title')->all();

        if ($model->load(Yii::$app->request->post())) {
            $model->file = UploadedFile::getInstance($model, 'file');
            $_selectedIngredients = Yii::$app->request->post('Ingredients');

            if (!self::checkDuplicateIngredients($_selectedIngredients['ingredient'])) {

                if ($model->save()) {
                    if ($model->file) {
                        $fileUploader = new FileUploader();
                        $model->file->saveAs($fileUploader->getLinkToFile($model->file->extension));
                        $images = $fileUploader->uploadFileLocally();
                        if ($images != false) {
                            $model->image = $images['origin'];
                            $model->thumbnail = $images['thumb'];
                        }
                    }
                    if (MealIngredient::batchInsertIngredients($model->meal_id, $_selectedIngredients)) {
                        AlternativeMealIngredients::batchInsertAlternativeIngredients($model->meal_id, $_selectedIngredients);
                    }
                    $model->recipe_url = $model->getRecipe("api/meal/");
                    $model->save(); //save recipe

                    return $this->redirect(['update', 'id' => $model->meal_id]);
                }
            }
        }

        return $this->render('create', [
                'model' => $model,
                'ingredients' => $ingredients,
            ]);
    }

    /**
     * Updates an existing Meal model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $ingredients = Ingredient::find()->groupBy('title')->all(); //get all ingredients
        $selectedIngredients = MealIngredient::fetchDataAsArray($model->mealIngredients); // get all selected ingredients
        $selectedAlternativeIngredients = AlternativeMealIngredients::fetchDataAsArray($model->alternativeIngredients); //get all alternative ingredients

        if ($model->load(Yii::$app->request->post()) ) {
            $model->file = UploadedFile::getInstance($model, 'file');
            $model->recipe_url = $model->getRecipe("api/meal/");
            $_selectedIngredients = Yii::$app->request->post('Ingredients');

            if (!self::checkDuplicateIngredients($_selectedIngredients['ingredient'])) {

                    if ($model->file) {
                        $fileUploader = new FileUploader();
                        $model->file->saveAs($fileUploader->getLinkToFile($model->file->extension));
                                $oldLinks = ['origin' => $model->image, 'thumb' => $model->thumbnail];
                        $images = $fileUploader->uploadFileLocally();
                        if ($images != false) {
                            $model->image = $images['origin'];
                            $model->thumbnail = $images['thumb'];
                        }
                    }

                    if ($model->save()) {

                    if (MealIngredient::batchInsertIngredients($model->meal_id, $_selectedIngredients)) {
                        AlternativeMealIngredients::deleteAll(['meal_id' => $model->meal_id]);
                        AlternativeMealIngredients::batchInsertAlternativeIngredients($model->meal_id, $_selectedIngredients);
                    }
                    return $this->redirect(['update', 'id' => $model->meal_id]);
                }
            }
        }
        return $this->render('update', [
            'model' => $model,
            'ingredients' => $ingredients,
            'selectedIngredients' => $selectedIngredients,
            'selectedAlternativeIngredients' => $selectedAlternativeIngredients
        ]);
    }

    public function actionLoadMore() {
        if (Yii::$app->request->isAjax) {
            $ingredients = Ingredient::find()->all(); //get all ingredients
            $load_more_from = Yii::$app->request->post('load_more_from');
            return $this->renderAjax('_ingredients', [
                'ingredients' => $ingredients,
                'load_more_from' => $load_more_from,
            ]);
        }
    }

    public function actionRemoveIngredient() {
        if (Yii::$app->request->isAjax) {
            $meal_id = Yii::$app->request->post('meal_id');
            $ingredient_id = Yii::$app->request->post('ingredient_id');
            if ((!isset($meal_id) || empty($meal_id)) || (!isset($ingredient_id) || empty($ingredient_id))) return false;

            MealIngredient::deleteAll(['meal_id' => $meal_id, 'ingredient_id' => $ingredient_id]);
            AlternativeMealIngredients::deleteAll(['meal_id' => $meal_id, 'default_ing_id' => $ingredient_id]);

            return true;
        }
    }

    /**
     * Deletes an existing Meal model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     */
    public function actionDelete($id)
    {
        $Meal = $this->findModel($id);
        $phase_menu = PhaseMenu::find()->where(['meal_id' => $id])->groupBy('day_of_phase, phase_id, category_id')->asArray()->all();
        $sorted_menu = [];
        foreach ($phase_menu as $row) {
            $sorted_menu[$row['phase_id']]['days'][] = $row['day_of_phase'];
            $sorted_menu[$row['phase_id']]['categories'][] = $row['category_id'];
        }
        foreach ($sorted_menu as $key => $row) {
            $sorted_menu[$key]['categories'] = array_unique($sorted_menu[$key]['categories']);
            $sorted_menu[$key]['days'] = array_unique($sorted_menu[$key]['days']);
        }
        $new = [];
        foreach ($sorted_menu as $key => &$row) {
            $result = PhaseMenu::find()
                ->select('phase_id, day_of_phase, category_id, meal_id, group, is_default')
                ->where(['phase_id' => $key, 'day_of_phase' => $row['days'], 'category_id' => $row['categories']])
                ->andWhere('meal_id <> :meal_id', [':meal_id' => $id])
                ->groupBy('day_of_phase, phase_id, category_id')
                ->asArray()
                ->all();
            $new[$key] = $result;

        }
        $db = Yii::$app->db;
        foreach ($new as $key => $row) {
            foreach ($row as $current) {
                try {
                    // Update PhaseMenu
                    $db->createCommand()
                        ->update(PhaseMenu::tableName(), ['is_default' => 1], ['phase_id' => $key, 'day_of_phase' => $current['day_of_phase'], 'category_id' => $current['category_id'], 'meal_id' => $current['meal_id']])
                        ->execute();
                    // Update UserMenu
                    $db->createCommand()
                        ->delete(UserMenu::tableName(), ['meal_id' => $current['meal_id'], 'phase_id' => $key, 'day_of_phase' => $current['day_of_phase'], 'category_id' => $current['category_id'], 'meal_id' => $id])
                        ->execute();
                } catch (Exception $e) {
                    Yii::$app->session->setFlash('error', $e->getMessage());
                }
            }
        }

        if ($Meal->image && $Meal->thumbnail) {
            $fileUploader = new FileUploader();
            $links = ['origin' => $Meal->image, 'thumb' => $Meal->thumbnail];
            $fileUploader->removeLocalMealsImages($id, $links);
        }

        PhaseMenu::deleteAll(['meal_id' => $id]);
        MealIngredient::deleteAll(['meal_id' => $id]);
        AlternativeMealIngredients::deleteAll(['meal_id' => $id]);
        $Meal->delete();

        return $this->redirect(['index']);
    }

    /**
     * Finds the Meal model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Meal the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Meal::findOne((int)$id)) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }

    /*
     * If new ingredients have been duplicated or if they've already existed
     * */
    private function checkDuplicateIngredients($ingredients) {
        $new_ingredients = [];
        $exist_ingredients = [];
        foreach ($ingredients as $ingredient) {
            if (isset($ingredient['new']) && !empty($ingredient['new'])) {
                $new_ingredients[] = $ingredient['new'];
            }
            if (isset($ingredient['exist']) && !empty($ingredient['exist'])) {
                $exist_ingredients[] = $ingredient['exist'];
            }
        }
        $duplicated_new = array_unique($new_ingredients);
        $duplicated_existing = array_unique($exist_ingredients);

        $existing_ingredients = Ingredient::find()->where(['title' => $duplicated_new])->asArray()->all();
        $_existing_ingredients = [];
        if (!empty($existing_ingredients)) {
            foreach ($existing_ingredients as $ingredient) {
                $_existing_ingredients[] = $ingredient['title'];
            }
            Yii::$app->session->setFlash('error', 'You have already used these meals: ' . implode(",", $_existing_ingredients) . '');
        }
        if ((count($duplicated_new) != count($new_ingredients)) || count($duplicated_existing) != count($exist_ingredients)) {
            Yii::$app->session->setFlash('error', 'You set a few ingredients which are duplicated');
            return true;
        }
        return false;
    }
}
