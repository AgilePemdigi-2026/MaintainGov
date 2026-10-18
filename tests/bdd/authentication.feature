Feature: Authentication and authorization
  Sebagai pengguna MaintainGov,
  saya ingin login dengan credential yang benar dan mendapat akses sesuai role,
  agar saya hanya dapat mengakses fitur yang menjadi tanggung jawab saya.

  Scenario: Maintenance member logs in with valid credentials
    Given I am on the login page
    When I enter valid Maintenance Team credentials
    Then I am redirected to "/analisis-feedback"
    And my session contains role "Tim Maintenance"

  Scenario: Developer logs in with valid credentials
    Given I am on the login page
    When I enter valid Developer credentials
    Then I am redirected to "/maintenance-backlog"
    And my session contains role "Developer"

  Scenario: Invalid credentials are rejected
    Given I am on the login page
    When I enter an incorrect email or password
    Then I see an error message without revealing which credential was wrong
    And I remain on the login page

  Scenario: Logout invalidates session
    Given I am authenticated as a Maintenance Team member
    When I logout
    And I try to access a protected endpoint with the old session
    Then I receive a 401 response
    And I am redirected to the login page
