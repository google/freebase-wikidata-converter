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

import sys
import os

#Combine files
temp = open('filter-temp.tsv', 'w')
for line in open(sys.argv[1]):
  temp.write(line.strip() + "\tf\n")
for line in open(sys.argv[2]):
  temp.write(line.strip() + "\tw\n")
temp.close()

#Sort
os.system('sort filter-temp.tsv > filter-temp.sorted.tsv')

#Filter
result = open(sys.argv[3], 'w')
matched = open(sys.argv[4], 'w')
duplicateCount = 0

fline = ''
for line in open('filter-temp.sorted.tsv'):
  if line.strip().endswith('f'):
    if fline != '':
      if line.find(fline.strip()[:-2]) == -1: #Ignore duplicates and sub statements
        result.write(fline.strip()[:-2] + '\n')
      else:
        duplicateCount += 1
    fline = line
    continue

  if fline == '':
    continue

  if fline.strip()[:-2] == line.strip()[:-2]:
    matched.write(line.strip()[:-2] + '\n')
    fline = ''
    continue

if fline != '':
  result.write(fline.strip()[:-2] + '\n')

matched.close()
result.close()
os.remove('filter-temp.tsv')
os.remove('filter-temp.sorted.tsv')

print "%d duplicates removed" % duplicateCount
