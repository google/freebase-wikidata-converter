"""
Copyright 2015 Google Inc. All Rights Reserved.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
"""

import gzip
import sys

filters = [
    '<http://rdf.freebase.com/ns/common.notable_for.display_name>',
    '<http://www.w3.org/1999/02/22-rdf-syntax-ns#type>',
    '<http://rdf.freebase.com/ns/type.object.type>',
    '<http://rdf.freebase.com/ns/type.type.instance>',
    '<http://rdf.freebase.com/ns/type.object.key>',
    '<http://www.w3.org/2000/01/rdf-schema#label>',
    '<http://rdf.freebase.com/ns/type.object.name>',
    '<http://rdf.freebase.com/ns/common.topic.topic_equivalent_webpage>',
    '<http://rdf.freebase.com/ns/common.topic.notable_for>',
    '<http://rdf.freebase.com/ns/common.notable_for.predicate>',
    '<http://rdf.freebase.com/ns/common.notable_for.notable_object>',
    '<http://rdf.freebase.com/ns/common.notable_for.object>',
    '<http://rdf.freebase.com/ns/common.topic.notable_types>',
    '<http://rdf.freebase.com/ns/common.topic.description>',
    '<http://rdf.freebase.com/key/dataworld.freeq>',
    '<http://rdf.freebase.com/ns/type.permission.controls>',
    '<http://rdf.freebase.com/ns/type.object.permission>',
    '<http://rdf.freebase.com/key/en>',
    '<http://rdf.freebase.com/ns/common.document.text>',
    '<http://rdf.freebase.com/ns/common.topic.article>',
    '<http://rdf.freebase.com/ns/common.topic.image>',
    '<http://rdf.freebase.com/ns/common.topic.alias>',
    '<http://rdf.freebase.com/ns/common.document.source_uri>',
    '<http://rdf.freebase.com/ns/dataworld.gardening_hint.last_referenced_by>',
    '<http://rdf.freebase.com/ns/type.object.id>',
    '<http://rdf.freebase.com/ns/dataworld.gardening_hint.replaced_by>',
    '<http://rdf.freebase.com/ns/freebase.object_hints.best_hrid>'
]

linecount = 0
filtercount = 0
result = open(sys.argv[2], 'w')
types = open(sys.argv[3], 'w')
labels = open(sys.argv[4], 'w')
for line in gzip.open(sys.argv[1]):
  linecount += 1
  if linecount % 1000000 == 0 : print filtercount, linecount / 1000000
  sub, pred, obj, dot = line.split("\t")
  if not (sub.startswith('<http://rdf.freebase.com/ns/m.') or sub.startswith('<http://rdf.freebase.com/ns/g.')):
    continue
  if pred == '<http://rdf.freebase.com/ns/type.object.type>':
    types.write(sub[28:-1] + "\t" + obj[24:-1] + "\n")
    continue
  if pred == '<http://rdf.freebase.com/ns/type.object.name>':
    labels.write(sub[28:-1] + "\t" + obj + "\n")
    continue
  if pred in filters:
    continue
  if pred.startswith('/fictional_universe'):
    continue
  if 'wikipedia' in pred:
    continue
  if 'topic_server' in pred:
    continue
  result.write(line)
  filtercount += 1

print filtercount, linecount

result.close()
types.close()
labels.close()

print "saved"
