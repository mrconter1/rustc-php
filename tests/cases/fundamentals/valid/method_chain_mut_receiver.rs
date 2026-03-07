// exit: 0
// stdout: 30

struct Acc {
    n: i32,
}

impl Acc {
    fn add(&mut self, x: i32) {
        self.n = self.n + x;
    }
    fn get(&self) -> i32 {
        self.n
    }
}

fn main() {
    let mut a = Acc { n: 0 };
    a.add(10);
    a.add(20);
    println!("{}", a.get());
}
