var DocumentPage = function() {
  var docInfo = element(by.css('.doc-info'));
  var discussionTabBtn = element(
    by.cssContainingText('.doc-content .nav-tabs a', 'Discussion')
  );
  var billTextTabBtn = element(
    by.cssContainingText('.doc-content .nav-tabs a', 'Bill Text')
  );
  var tableOfContents = element(by.css('.toc'));

  this.get = function() {
    browser.get('/docs/example-document');
  };

  this.info = {
    title: docInfo.element(by.css('.heading')),
    content: element(by.css('#content'))
  };

  this.stats = {
    participants: element(by.binding('doc.user_count')),
    comments: element(by.binding('doc.comment_count')),
    annotations: element(by.binding('doc.annotation_count')),

    updated: element(by.css('.stats-history .date')),

    supportCount: element(by.binding('doc.support')),
    supportChart: element(by.css('.support-chart .chart .support rect')),
    opposeCount: element(by.binding('doc.oppose')),
    opposeChart: element(by.css('.support-chart .chart .oppose rect'))
  };

  this.buttons = {
    support: element(by.css('#doc-support')),
    oppose: element(by.css('#doc-oppose'))
  };

  this.showDiscussion = function() {
    discussionTabBtn.click();
  };

  this.showBillText = function() {
    billTextTabBtn.click();
  };

  this.showTableOfContents = function() {
    element(by.css('.toc-title-side')).click();
    browser.driver.sleep(1000);
  };

  this.hideTableOfContents = function() {
    element(by.css('.toc-close')).click();
    browser.driver.sleep(1000);
  };

  this.getComment = function(row) {
    var comment = element(by.repeater('comment in doc.comments').row(row));

    return {
      element: comment,
      name: comment.element(by.css('.author')),
      body: comment.element(by.css('.content')),
      time: comment.element(by.css('.date'))
    };
  };

  this.getCommentReply = function(row) {
    var reply = element(by.repeater('reply in comment.comments').row(row));

    return {
      element: reply,
      name: reply.element(by.css('.author')),
      body: reply.element(by.css('.content')),
      time: reply.element(by.css('.date'))
    };
  };

  this.showCommentReplies = function(comment) {
    comment.element.element(by.css('.doc-replies-count')).click();
  };

  this.loginToCommentLink = function() {
    return element(
      by.cssContainingText('.comment-field:nth-child(2) a', 'Login to comment')
    );
  };

  this.pageWithTableOfContents = function() {
    return element(by.css('.single-doc.toc-open'));
  };

};

module.exports = DocumentPage;
