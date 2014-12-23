<?php

namespace Drupal\quizz_ddlines;

use Drupal\quizz_question\Entity\Question;
use Drupal\quizz_question\ResponseHandler;

/**
 * Extension of QuizQuestionResponse
 */
class DDLinesResponse extends ResponseHandler {

  protected $base_table = 'quiz_ddlines_user_answers';

  /**
   * Contains a assoc array with label-ID as key and hotspot-ID as value.
   * @var array
   */
  protected $user_answers = array();

  public function __construct($result_id, Question $question, $tries = NULL) {
    parent::__construct($result_id, $question, $tries);

    // Is answers set in form?
    if (isset($tries)) {
      // Tries contains the answer decoded as JSON:
      // {"label_id":x,"hotspot_id":y},{…}
      $decoded = json_decode($tries);
      if (is_array($decoded)) {
        foreach ($decoded as $answer) {
          $this->user_answers[$answer->label_id] = $answer->hotspot_id;
        }
      }
    }
    // Load from database
    else {
      $query = db_query(
        'SELECT label_id, hotspot_id FROM {quiz_ddlines_user_answers} ua
         LEFT OUTER JOIN {quiz_ddlines_user_answer_multi} uam ON(uam.user_answer_id = ua.id)
         WHERE ua.result_id = :result_id AND ua.question_qid = :question_qid AND ua.question_vid = :question_vid', array(
          ':result_id'    => $result_id,
          ':question_qid' => $this->question->qid,
          ':question_vid' => $this->question->vid
      ));
      while ($row = $query->fetch()) {
        $this->user_answers[$row->label_id] = $row->hotspot_id;
      }
    }
  }

  /**
   * Save the current response.
   */
  public function save() {
    $user_answer_id = db_insert('quiz_ddlines_user_answers')
      ->fields(array(
          'question_qid' => $this->question->qid,
          'question_vid' => $this->question->vid,
          'result_id'    => $this->result_id,
      ))
      ->execute();

    // Each alternative is inserted as a separate row
    $query = db_insert('quiz_ddlines_user_answer_multi')
      ->fields(array('user_answer_id', 'label_id', 'hotspot_id'));
    foreach ($this->user_answers as $key => $value) {
      $query->values(array($user_answer_id, $key, $value));
    }
    $query->execute();
  }

  /**
   * Delete the response.
   */
  public function delete() {
    $user_answer_ids = array();
    $query = db_query('SELECT id FROM {quiz_ddlines_user_answers} WHERE question_qid = :qid AND question_vid = :vid AND result_id = :result_id', array(':qid' => $this->question->qid, ':vid' => $this->question->vid, ':result_id' => $this->result_id));
    while ($answer = $query->fetch()) {
      $user_answer_ids[] = $answer->id;
    }

    if (!empty($user_answer_ids)) {
      db_delete('quiz_ddlines_user_answer_multi')
        ->condition('user_answer_id', $user_answer_ids, 'IN')
        ->execute();
    }

    parent::delete();
  }

  /**
   * Calculate the score for the response.
   */
  public function score() {
    $results = $this->getDragDropResults();

    // Count number of correct answers:
    $correct_count = 0;

    foreach ($results as $result) {
      $correct_count += ($result == QUIZZ_DDLINES_CORRECT) ? 1 : 0;
    }

    return $correct_count;
  }

  /**
   * Get the user's response.
   */
  public function getResponse() {
    return $this->user_answers;
  }

  public function getFeedbackValues() {
    // Have to do node_load, since quiz does not do this. Need the field_image…
    $img_field = field_get_items('quiz_question', quiz_question_entity_load($this->question->qid), 'field_image');
    $img_rendered = theme('image', array('path' => image_style_url('large', $img_field[0]['uri'])));

    $image_path = base_path() . drupal_get_path('module', 'quizz_ddlines') . '/theme/images/';

    $html = '<h3>' . t('Your answers') . '</h3>';
    $html .= '<div class="icon-descriptions"><div><img src="' . $image_path . 'icon_ok.gif">' . t('Means alternative is placed on the correct spot') . '</div>';
    $html .= '<div><img src="' . $image_path . 'icon_wrong.gif">' . t('Means alternative is placed on the wrong spot, or not placed at all') . '</div></div>';
    $html .= '<div class="quiz-ddlines-user-answers" id="' . $this->question->qid . '">';
    $html .= $img_rendered;
    $html .= '</div>';
    $html .= '<h3>' . t('Correct answers') . '</h3>';
    $html .= '<div class="quiz-ddlines-correct-answers" id="' . $this->question->qid . '">';
    $html .= $img_rendered;
    $html .= '</div>';

    // No form to put things in, are therefore using the js settings instead
    $settings = array();
    $correct_id = "correct-{$this->question->qid}";
    $settings[$correct_id] = json_decode($this->question->ddlines_elements);
    $elements = $settings[$correct_id]->elements;

    // Convert the user's answers to the same format as the correct answers
    $answers = clone $settings[$correct_id];
    // Keep everything except the elements:
    $answers->elements = array();

    $elements_answered = array();

    foreach ($this->user_answers as $label_id => $hotspot_id) {

      if (!isset($hotspot_id)) {
        continue;
      }

      // Find correct answer:
      $element = array(
          'feedback_wrong'   => '',
          'feedback_correct' => '',
          'color'            => $this->getElementColor($elements, $label_id)
      );

      $label = $this->getLabel($elements, $label_id);
      $hotspot = $this->getHotspot($elements, $hotspot_id);

      if (isset($hotspot)) {
        $elements_answered[] = $hotspot->id;
        $element['hotspot'] = $hotspot;
      }

      if (isset($label)) {
        $elements_answered[] = $label->id;
        $element['label'] = $label;
      }

      $element['correct'] = $this->isAnswerCorrect($elements, $label_id, $hotspot_id);
      $answers->elements[] = $element;
    }

    // Need to add the alternatives not answered by the user.
    // Create dummy elements for these:
    foreach ($elements as $el) {
      if (!in_array($el->label->id, $elements_answered)) {
        $element = array(
            'feedback_wrong'   => '',
            'feedback_correct' => '',
            'color'            => $el->color,
            'label'            => $el->label,
        );
        $answers->elements[] = $element;
      }

      if (!in_array($el->hotspot->id, $elements_answered)) {
        $element = array(
            'feedback_wrong'   => '',
            'feedback_correct' => '',
            'color'            => $el->color,
            'hotspot'          => $el->hotspot,
        );
        $answers->elements[] = $element;
      }
    }

    $settings["answers-{$this->question->qid}"] = $answers;
    $settings['mode'] = 'result';
    $settings['execution_mode'] = $this->question->execution_mode;
    $settings['hotspot']['radius'] = $this->question->hotspot_radius;

    // Image path:
    $settings['quiz_imagepath'] = base_path() . drupal_get_path('module', 'quizz_ddlines') . '/theme/images/';

    drupal_add_js(array('quiz_ddlines' => $settings), 'setting');

    _quiz_ddlines_add_js_and_css();

    return array('#markup' => $html);
  }

  private function getElementColor($list, $id) {
    foreach ($list as $element) {
      if ($element->label->id == $id) {
        return $element->color;
      }
    }
  }

  private function getHotspot($list, $id) {
    foreach ($list as $element) {
      if ($element->hotspot->id == $id) {
        return $element->hotspot;
      }
    }
  }

  private function getLabel($list, $id) {
    foreach ($list as $element) {
      if ($element->label->id == $id) {
        return $element->label;
      }
    }
  }

  private function isAnswerCorrect($list, $label_id, $hotspot_id) {
    foreach ($list as $element) {
      if ($element->label->id == $label_id) {
        return ($element->hotspot->id == $hotspot_id);
      }
    }
    return FALSE;
  }

  /**
   * Get a list of the labels, tagged correct, false, or no answer
   */
  private function getDragDropResults() {
    $results = array();

    // Iterate through the correct answers, and check the users answer:
    foreach (json_decode($this->question->ddlines_elements)->elements as $element) {
      $source_id = $element->label->id;

      if (isset($this->user_answers[$source_id])) {
        $results[$element->label->id] = ($this->user_answers[$source_id] == $element->hotspot->id) ? QUIZZ_DDLINES_CORRECT : QUIZZ_DDLINES_WRONG;
      }
      else {
        $results[$element->label->id] = 0;
      }
    }

    return $results;
  }

}
