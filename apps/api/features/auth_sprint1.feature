@http @sprint1 @auth
Feature: Sprint 1 authentication and access control
  Authentication uses server-side sessions, CSRF tokens, and role checks.

  Scenario: An unauthenticated client cannot access user details
    Given a fresh unauthenticated API session
    Then the HTTP response is 401

  Scenario: Maintenance can sign in
    Given a fresh unauthenticated API session
    When I log in as "maintenance"
    Then my API role is "Tim Maintenance"

  Scenario: Developer can sign in
    Given a fresh unauthenticated API session
    When I log in as "developer"
    Then my API role is "Developer"

  Scenario: Incorrect password produces a generic error
    Given a fresh unauthenticated API session
    When I log in with an invalid password
    Then the HTTP response is 401
    And the login error does not reveal which credential was wrong

  Scenario: Logging out revokes the previous session
    Given a fresh unauthenticated API session
    When I log in as "maintenance"
    And I log out and replay the old session cookie
    Then the HTTP response is 401

  Scenario: Developer cannot access feedback analysis
    Given a fresh unauthenticated API session
    When I log in as "developer"
    And I request the protected analysis endpoint
    Then the HTTP response is 403
