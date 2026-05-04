@local @local_jomot
Feature: Just One More Thing admin settings
  In order to configure AI question generation
  As an admin
  I need to be able to set the default AI prompt

  Scenario: Admin can view and save the default AI prompt
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > Just One More Thing" in site administration
    Then I should see "Default AI prompt"
    And I should see "Generate {numquestions} multiple choice questions"
    When I set the field "s_local_jomot_default_ai_prompt" to "Create {numquestions} questions from this text."
    And I press "Save changes"
    Then I should see "Changes saved"
    And I log out
