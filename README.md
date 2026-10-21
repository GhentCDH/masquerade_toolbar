# Masquerade toolbar

See the site as a role with one click, from the toolbar. Builds on
[Masquerade](https://www.drupal.org/project/masquerade).

- **Masquerade**, at the right of the toolbar: a menu under the button with
  the roles you may masquerade as. It closes on a click elsewhere or Escape.
- **While masquerading** the button is red, and the menu starts with
  *Unmasquerade* (instead of Masquerade's own tab), then the roles with the
  current one highlighted. Another role switches back and on in one click;
  which roles you see is decided by you, not by the test user. The button
  never moves.
- Users without the toolbar get an *Unmasquerade* button at the bottom
  right. Switching keeps you on the same page.

## Never up

For all of Masquerade, also its own form and block:

- nobody masquerades as an administrator role (administrators do not need
  to);
- nobody masquerades as roles with a permission they do not have themselves,
  whatever Masquerade's permissions say.

Within that, Masquerade's permissions decide, such as *Masquerade as
Redactor*. Never give *Masquerade as any user* to others than administrators.

## Test users

Each role (not the administrator roles) has a test user, `test_<role>`,
created the first time someone masquerades as the role. They exist only for
masquerading:

- **Exactly one role**, always active: every save (a form, Drush, a bulk
  action) puts that back.
- **Nobody edits them**, administrators neither.
- **Visible only to who may masquerade**: on *People*, in user autocompletes
  and on their profile.
- **No login** other than by masquerading: a password or a one-time login
  link logs them out at once. Their password is random and unknown, their
  e-mail address does not exist.
- **Deleting** needs the permission *Delete masquerade test users*; their
  content then belongs to Anonymous. The test user is recreated the next time
  someone masquerades as the role. Deleting a role deletes its test user.
- **Uninstalling** this module deletes all test users; their content belongs
  to Anonymous.

Which accounts are test users is stored by this module, not derived from
their names.

## Requirements

Masquerade 2.2, and core's Toolbar module (installed with this module). The
menu is part of the Toolbar: on a site with the Navigation module instead,
there is no menu, but the rules above still apply to Masquerade.

## Setup

    composer require drupal/masquerade_toolbar
    drush en masquerade_toolbar

## Tests

Kernel tests for the rules (never up, the test users), and a functional test
for the menu: `phpunit --group masquerade_toolbar`.
