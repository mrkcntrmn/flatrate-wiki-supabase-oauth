# META-001B diagnostic note

Production `2bc9c7a0137fb0a2b9ec0680b634c49ec19927b2` proves the Flarum 1.8 reply-marker UI compatibility fix: the reply control renders and survives restart. The remaining failure is the marked-reply submit/persistence path. Investigation is evidence-first and should not mutate production until request/log/DB evidence identifies the failing layer.
