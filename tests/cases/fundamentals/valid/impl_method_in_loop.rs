// exit: 55

struct Acc {
    total: i32,
    unused: i32,
}

impl Acc {
    fn new() -> Acc {
        Acc { total: 0, unused: 0 }
    }

    fn add(&mut self, n: i32) {
        self.total = self.total + n;
    }

    fn get(&self) -> i32 {
        self.total
    }
}

fn main() {
    let mut a = Acc::new();
    let mut i = 1;
    while i <= 10 {
        a.add(i);
        i = i + 1;
    }
    exit(a.get());
}
